<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Franchise, Pet, User, Proposal, ProductVersion};
use App\Services\Attribution\AttributionService;
use App\Services\Domain\ProposalService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, URL};

class RegistrationController extends Controller
{
    public function create(Request $request)
    {
        $request->validate(['product_version_id'=>'nullable|integer|min:1']);
        $selectedVersion = null;
        if (!$request->filled('token')) {
            $id = $request->old('product_version_id', $request->input('product_version_id', $request->session()->get('sf_suggested_product_version_id')));
            if ($id) $selectedVersion = $this->offeredVersion((int) $id);
        }
        return view('auth.register', compact('selectedVersion'));
    }

    public function store(Request $request,CustomerController $customers)
    {
        $request->merge(['document'=>preg_replace('/\D/','',$request->input('document',''))]);
        $invite=null;
        if ($request->filled('invite_token')) {
            $token=$request->string('invite_token')->toString();
            abort_unless(strlen($token)>=32 && strlen($token)<=128,422,'Convite inválido.');
            $invite=Proposal::where('link_token_hash',hash('sha256',$token))->whereNull('link_revoked_at')->where('link_expires_at','>',now())->firstOrFail();
        }
        $data=$request->validate([
            'name'=>'required|string|min:3|max:180','document'=>'required|digits:11',
            'email'=>'required|email|max:180|unique:users,email','phone'=>'required|string|max:30',
            'address'=>($invite?'nullable':'required_without:street').'|nullable|string|max:500','password'=>'required|string|min:12|confirmed','privacy_accepted'=>'accepted',
            'pet_name'=>($invite?'nullable':'required').'|string|max:100','pet_species'=>($invite?'nullable':'required').'|in:dog,cat','pet_birth_date'=>($invite?'nullable':'required').'|date|before_or_equal:today',
            'rg'=>'nullable|string|max:40','city'=>'nullable|string|max:150','neighborhood'=>'nullable|string|max:150','state'=>'nullable|string|size:2',
            'pet_breed'=>'nullable|string|max:100','pet_color'=>'nullable|string|max:100','pet_sex'=>'nullable|in:male,female,unknown',
            'product_version_id'=>'nullable|integer|min:1|exists:product_versions,id',
        ] + \App\Services\CustomerAddress::rules() + \App\Services\Attribution\CustomerAcquisition::rules());
        $selectedVersion = !$invite && !empty($data['product_version_id']) ? $this->offeredVersion((int) $data['product_version_id']) : null;
        $customers->validateCpf($data['document']);
        $central=Franchise::where('code','CENTRAL')->where('active',true)->firstOrFail();
        $existing=Customer::where('document',$data['document'])->first();
        if ($existing) abort_unless($invite && $invite->customer_id===$existing->id && strcasecmp($existing->email??'',$data['email'])===0 && !User::where('customer_id',$existing->id)->exists(),422,'Não foi possível criar a conta com estes dados. Entre com seu acesso ou solicite apoio à unidade.');
        elseif ($invite) abort(422,'Os dados não correspondem ao convite.');
        $proposal = null;
        $user=DB::transaction(function () use($data,$central,$existing,$selectedVersion,$request,&$proposal) {
            if ($existing) $customer=$existing;
            else {
                $customer=Customer::create(['name'=>$data['name'],'rg'=>$data['rg']??null,'document'=>$data['document'],'email'=>$data['email'],'phone'=>$data['phone'],'address'=>\App\Services\CustomerAddress::from($data),'originating_franchise_id'=>$central->id,'contact_verified_at'=>config('plans.demo')?now():null]);
                $pet = Pet::create(['customer_id'=>$customer->id,'name'=>$data['pet_name'],'species'=>$data['pet_species'],'birth_date'=>$data['pet_birth_date'],'breed'=>$data['pet_breed']??null,'color'=>$data['pet_color']??null,'sex'=>$data['pet_sex']??'unknown']);
            }
            $user = User::create(['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password'],'role'=>'customer','customer_id'=>$customer->id,'active'=>true,'email_verified_at'=>config('plans.demo')?now():null]);
            $attribution=$existing && $customer->acquisition_profile
                ? app(AttributionService::class)->proposalData($request,[],$user)
                : app(\App\Services\Attribution\CustomerAcquisition::class)->record($customer,$user,$request,$data);
            if ($selectedVersion && isset($pet)) {
                $proposal = app(ProposalService::class)->create([
                    'customer_id'=>$customer->id, 'pet_id'=>$pet->id, 'product_version_id'=>$selectedVersion->id,
                    'originating_franchise_id'=>$attribution->originatingFranchiseId ?? $central->id,
                    'channel'=>'site', 'prefill_by'=>'customer', 'signature_method'=>'digital',
                ], $user, $attribution);
            }
            return $user;
        });
        Auth::login($user); $request->session()->regenerate();
        if ($proposal) {
            $request->session()->put('sf_online_proposal_id', $proposal->id);
            $request->session()->forget('sf_suggested_product_version_id');
        }
        if ($proposal) {
            return redirect()->route('proposals.show', $proposal)->with('success','Seus dados e os do pet foram salvos. Confira o contrato do plano escolhido para continuar.');
        }
        return redirect()->route('portal')->with('success',config('plans.demo')?'Conta de demonstração criada.':'Conta criada. Confira o contrato do plano para continuar; o e-mail é confirmado por último.');
    }

    public function verify(Request $request,User $user,string $hash)
    {
        abort_unless($request->user()->id===$user->id && hash_equals(sha1($user->email),$hash),403);
        DB::transaction(function () use($user) {
            $user->forceFill(['email_verified_at'=>now()])->save();
            $user->customer?->update(['contact_verified_at'=>now()]);
        });
        app(\App\Services\Domain\ContactVerificationService::class)->activatePending((int) $user->customer_id);
        $proposal = Proposal::whereKey($request->session()->pull('sf_online_proposal_id'))
            ->where('customer_id', $user->customer_id)->whereNull('locked_at')->where('expires_at','>',now())->first();
        return $proposal ? redirect()->route('proposals.show', $proposal)->with('success','Contato verificado. Confira seu contrato para continuar.')
            : redirect()->route('portal')->with('success','Contato verificado.');
    }

    private function offeredVersion(int $id): ProductVersion
    {
        $version = ProductVersion::with('product')->offered()->whereKey($id)->first();
        abort_unless($version,422,'Este plano não está disponível. Escolha outro plano na página de contratação.');
        return $version;
    }

    public function resend(Request $request)
    {
        if(!$request->user()->email_verified_at) $this->notify($request->user());
        return back()->with('success','Confira seu e-mail para verificar o contato.');
    }

    private function notify(User $user): void
    {
        VerifyEmail::createUrlUsing(fn($notifiable)=>URL::temporarySignedRoute('verification.verify',now()->addMinutes(60),['user'=>$notifiable->id,'hash'=>sha1($notifiable->email)]));
        $user->notify(new VerifyEmail);
    }
}
