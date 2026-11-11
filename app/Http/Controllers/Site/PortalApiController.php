<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\{Controller,CustomerController};
use App\Models\{CancellationRequest,ContractDocument,Customer,Franchise,Invoice,Pet,ProductVersion,Proposal,Subscription,User};
use App\Notifications\PortalVerifyEmail;
use App\Services\{CustomerAddress};
use App\Services\Attribution\CustomerAcquisition;
use App\Services\Contracts\{ContractService,PdfPages,SigningEvidence};
use App\Services\Domain\{Audit,EligibilityService,FinancialService,ProposalService};
use App\Services\Integrations\{IntegrationPendingException,VindiCheckoutService};
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\{DB,Hash,Log,Password,RateLimiter,Storage,URL};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalApiController extends Controller
{
    public function catalog()
    {
        $offers=ProductVersion::preferredOffers()->with(['product','benefitRules'])->orderByDesc('version')->get()->unique('product_id');
        if (config('site_portal.show_drafts')) {
            $drafts=ProductVersion::where('status','draft')->where('is_demo',false)->whereNotNull('contract_layout')->whereHas('product',fn($q)=>$q->where('active',true))
                ->with(['product','benefitRules'])->orderByDesc('version')->get()->unique('product_id')->reject(fn($v)=>$offers->contains('product_id',$v->product_id));
            $offers=$offers->concat($drafts);
        }
        $offers=$offers->sortBy('price_cents')->values();
        return response()->json(['offers'=>$offers->map(fn($v)=>[
            'id'=>$v->id,'code'=>$v->product->code,'name'=>$v->name,'category'=>$v->product->category,
            'description'=>$v->description,'price_cents'=>$v->price_cents,'enrollment_fee_cents'=>$v->enrollment_fee_cents,
            'billing_cycle'=>$v->billing_cycle,'duration_months'=>$v->duration_months,'species'=>$v->species,
            'demo'=>$v->is_demo || $v->status==='local_preview','informational'=>$v->status==='draft',
            'benefits'=>$v->benefitRules->map(fn($b)=>$b->only(['name','description','waiting_days','quantity_limit','period_type','coverage_type','discount_basis_points'])),
        ])]);
    }

    // Quem aparece como "Indicado por" no cadastro aberto pelo link de cadastro.
    public function attributionPreview(Request $r)
    {
        $link=app(\App\Services\Attribution\AttributionService::class)->resume($r,$r->input('attribution_ref'));
        return response()->json(['referrer'=>$link?\App\Services\Attribution\AttributionService::referrerLabel($link):null]);
    }

    // Pergunta do cadastro: já existe acesso com este CPF ou e-mail? O gateway limita por IP para não servir de consulta em massa.
    public function accountCheck(Request $r)
    {
        $data=$r->validate(['document'=>'nullable|string|max:20','email'=>'nullable|email|max:180']);
        $cpf=preg_replace('/\D/','',(string)($data['document']??'')); $email=trim((string)($data['email']??''));
        // Confere na etapa dos dados o que o cadastro final recusaria, para o cliente não chegar ao fim e preencher tudo de novo.
        if ($cpf!=='') {
            try { app(CustomerController::class)->validateCpf($cpf); }
            catch(ValidationException) { return response()->json(['code'=>'INVALID_CPF','field'=>'document','message'=>'Confira o CPF: os números digitados não formam um CPF válido.']); }
        }
        if ($existing=$this->existingAccount($cpf,$email)) return response()->json($existing);
        if ($email!=='' && User::where('email',$email)->exists()) return response()->json(['code'=>'EMAIL_IN_USE','field'=>'email','message'=>'Este e-mail já é usado em outro acesso. Use outro e-mail.']);
        return response()->json(['code'=>'AVAILABLE']);
    }

    public function login(Request $r)
    {
        $data=$r->validate(['email'=>'required|email|max:180','password'=>'required|string|max:1000']);
        $user=User::where('email',$data['email'])->first();
        $valid=$user && $user->active && $user->role==='customer' && $user->customer?->active && Hash::check($data['password'],$user->password);
        if (!$valid) throw ValidationException::withMessages(['email'=>'Não foi possível entrar com os dados informados.']);
        return response()->json($this->credentials($user));
    }

    public function register(Request $r,CustomerController $customers,ProposalService $proposals)
    {
        $link=app(\App\Services\Attribution\AttributionService::class)->resume($r,$r->input('attribution_ref'));
        $r->merge(['document'=>preg_replace('/\D/','',(string)$r->input('document'))]);
        // Someone who already has an account is sent to the login instead of failing the signup.
        if ($existing=$this->existingAccount((string)$r->input('document'),trim((string)$r->input('email')))) return response()->json($existing,409);
        $data=$r->validate([
            'name'=>'required|string|min:3|max:180','document'=>'required|digits:11','email'=>'required|email|max:180|unique:users,email',
            'phone'=>'required|string|max:30','rg'=>'nullable|string|max:40','password'=>'required|string|min:12|max:1000|confirmed',
            'privacy_accepted'=>'accepted','street'=>'required|string|max:200','number'=>'required|string|max:30',
            'complement'=>'nullable|string|max:120','neighborhood'=>'required|string|max:150','city'=>'required|string|max:150',
            'state'=>'required|string|size:2','zip'=>'required|regex:/^\d{5}-?\d{3}$/',
            'product_version_id'=>'nullable|integer|min:1',
        ]+($r->has('pets') ? [
            // Cadastro em etapas: um ou mais pets de uma vez (máx. 5).
            'pets'=>'required|array|min:1|max:5','pets.*.name'=>'required|string|max:100','pets.*.species'=>'required|in:dog,cat',
            // Raça, cor e sexo entram no contrato: são obrigatórios. Sexo do titular e castração: o SimplesVet exige para cadastrar.
            'pets.*.birth_date'=>'required|date|before_or_equal:today','pets.*.breed'=>'required|string|max:100',
            'pets.*.color'=>'required|string|max:100','pets.*.sex'=>'required|in:male,female','pets.*.neutered'=>'required|boolean',
            'holder_sex'=>'required|in:male,female',
        ] : [
            'pet_name'=>'required|string|max:100','pet_species'=>'required|in:dog,cat','pet_birth_date'=>'required|date|before_or_equal:today',
            'pet_breed'=>'nullable|string|max:100','pet_color'=>'nullable|string|max:100','pet_sex'=>'nullable|in:male,female,unknown',
            'pet_neutered'=>'nullable|boolean','holder_sex'=>'nullable|in:male,female',
        ])+CustomerAcquisition::rules(),['holder_sex.required'=>'Informe o sexo do titular.','pets.*.neutered.required'=>'Informe se o pet é castrado.']);
        $petList=isset($data['pets']) ? array_values($data['pets']) : [['name'=>$data['pet_name'],'species'=>$data['pet_species'],'birth_date'=>$data['pet_birth_date'],
            'breed'=>$data['pet_breed']??null,'color'=>$data['pet_color']??null,'sex'=>$data['pet_sex']??null,'neutered'=>$data['pet_neutered']??null]];
        $customers->validateCpf($data['document']);
        if (Customer::where('document',$data['document'])->exists()) throw ValidationException::withMessages(['document'=>'Não foi possível criar uma conta com estes dados. Entre com seu acesso ou solicite apoio à unidade.']);
        $version=empty($data['product_version_id'])?null:$this->offered((int)$data['product_version_id']);
        $unit=Franchise::where('code','CENTRAL')->where('active',true)->firstOrFail();
        $proposalIds=[];
        $user=DB::transaction(function() use($r,$data,$petList,$unit,$version,$proposals,$link,&$proposalIds) {
            $customer=Customer::create(['name'=>$data['name'],'document'=>$data['document'],'rg'=>$data['rg']??null,'email'=>$data['email'],
                'phone'=>$data['phone'],'sex'=>$data['holder_sex']??null,'address'=>CustomerAddress::from($data),'originating_franchise_id'=>$unit->id,'contact_verified_at'=>config('plans.demo')?now():null]);
            $createdPets=array_map(fn($p)=>Pet::create(['customer_id'=>$customer->id,'name'=>$p['name'],'species'=>$p['species'],'birth_date'=>$p['birth_date'],
                'breed'=>$p['breed']??null,'color'=>$p['color']??null,'sex'=>$p['sex']??'unknown','neutered'=>isset($p['neutered'])?(bool)$p['neutered']:null]),$petList);
            $user=User::create(['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password'],'role'=>'customer',
                'customer_id'=>$customer->id,'active'=>true,'email_verified_at'=>config('plans.demo')?now():null]);
            // Pelo link, a origem e quem indicou vêm dele: o que o cliente digitar nesses campos não vale.
            $attribution=app(CustomerAcquisition::class)->record($customer,$user,$r,$link?array_diff_key($data,array_flip(['acquisition_source','referrer_name','referrer_customer_id','campaign_name'])):$data);
            if ($version) foreach($createdPets as $pet) $proposalIds[]=$proposals->create(['customer_id'=>$customer->id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
                'originating_franchise_id'=>$unit->id,'channel'=>'site','prefill_by'=>'customer','signature_method'=>'digital'],$user,$attribution)->id;
            Audit::record('portal.customer_registered',$customer,$user);
            return $user;
        });
        $sent=true; // a confirmação é pedida depois do pagamento
        return response()->json($this->credentials($user)+['proposal_id'=>$proposalIds[0]??null,'proposal_ids'=>$proposalIds,'verification_sent'=>$sent],201);
    }

    private function existingAccount(string $cpf,string $email): ?array
    {
        $customer=preg_match('/^\d{11}$/D',$cpf)?Customer::where('document',$cpf)->first():null;
        if (($customer && User::where('customer_id',$customer->id)->where('role','customer')->exists())
            || ($email!=='' && User::where('email',$email)->where('role','customer')->exists())) {
            return ['code'=>'ACCOUNT_EXISTS','by'=>($customer && User::where('customer_id',$customer->id)->where('role','customer')->exists())?'document':'email','message'=>'Você já tem cadastro. Entre com seu e-mail e senha. Se não lembrar a senha, use “Esqueci minha senha”.'];
        }
        // Registered at a unit without online access: a password login would not work, so the unit sends the access link.
        return $customer?['code'=>'CUSTOMER_WITHOUT_ACCESS','message'=>'Seu CPF já está cadastrado em uma de nossas unidades. Peça à unidade o link para criar seu acesso ao site.']:null;
    }

    private function credentials(User $user): array
    {
        $raw=bin2hex(random_bytes(32));
        $expires=now()->addHours(max(1,min(168,(int)config('site_portal.token_hours',24))));
        DB::table('portal_tokens')->insert(['user_id'=>$user->id,'token_hash'=>hash('sha256',$raw),
            'password_fingerprint'=>hash_hmac('sha256',$user->password,(string)config('app.key')),
            'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('portal_tokens')->where('user_id',$user->id)->where('expires_at','<',now())->delete();
        return ['token'=>$raw,'expires_at'=>$expires->toIso8601String(),'user'=>['name'=>$user->name,'email'=>$user->email,'verified'=>(bool)$user->email_verified_at]];
    }

    /** Link de entrada na área do cliente da gestão para quem já está com a sessão aberta no site. */
    public function managementAccess(Request $r)
    {
        abort_unless($r->user()->role==='customer' && $r->user()->active,403);
        return response()->json(['url'=>URL::temporarySignedRoute('login.site',now()->addMinutes(2),['user'=>$r->user()->id])]);
    }

    public function logout(Request $r)
    {
        DB::table('portal_tokens')->where('id',$r->attributes->get('portal_token_id'))->delete();
        return response()->json(['ok'=>true]);
    }

    public function me(Request $r,EligibilityService $eligibility,FinancialService $financial,VindiCheckoutService $checkout)
    {
        $user=$r->user();
        $basic=['user'=>['name'=>$user->name,'email'=>$user->email,'verified'=>(bool)$user->email_verified_at],'demo'=>(bool)config('plans.demo')];
        $customer=$user->customer->load(['pets','subscriptions.productVersion.benefitRules','subscriptions.pet','subscriptions.proposal',
            'subscriptions.documents','subscriptions.cancellationRequest','invoices.subscription','proposals.pet','proposals.productVersion']);
        return response()->json($basic+[
            'customer'=>['name'=>$customer->name,'phone'=>$customer->phone,'address'=>CustomerAddress::display($customer->address)],
            'financial'=>$financial->customerStatus($customer),
            // occupied: categorias em que o pet já tem contrato ou reserva (o site não oferece outro plano delas).
            'pets'=>$customer->pets->map(fn($p)=>$p->only(['id','name','species','breed','color','sex','birth_date'])
                +['occupied'=>\App\Models\PetProductSlot::where('pet_id',$p->id)->where('state','<>','released')->pluck('product_category')->unique()->values()]),
            'subscriptions'=>$customer->subscriptions->map(function($s) use($eligibility,$checkout) {
                $result=$eligibility->subscription($s);
                $document=$s->documents->where('kind','final_contract')->where('status','valid')->sortByDesc('version')->first();
                return ['id'=>$s->id,'number'=>$s->number,'name'=>$s->productVersion->name,'pet_id'=>$s->pet_id,'pet_name'=>$s->pet->name,
                    'status'=>$s->status,'document_status'=>$s->document_status,'document_id'=>$document?->id,'proposal_id'=>$s->proposal_id,
                    'effective_start_at'=>$s->effective_start_at?->toIso8601String(),'ends_at'=>$s->ends_at?->toIso8601String(),
                    'can_checkout'=>$checkout->available($s),'can_cancel'=>!$s->isTerminal() && !$s->cancellationRequest,
                    'cancellation_status'=>$s->cancellationRequest?->status,'impediments'=>$result['impediments'],
                    'benefits'=>$s->productVersion->benefitRules->map(function($b) use($s,$eligibility) {
                        $state=$eligibility->benefit($s,$b);
                        return ['name'=>$b->name,'description'=>$b->description,'eligible'=>$state['eligible'],'remaining'=>$state['remaining'],
                            'available_from'=>$state['available_from'],'impediments'=>$state['impediments']];
                    })];
            }),
            'invoices'=>$customer->invoices->sortByDesc('due_at')->values()->map(fn($i)=>[
                'id'=>$i->id,'subscription_number'=>$i->subscription->number,'amount_cents'=>$i->amount_cents,'paid_cents'=>$i->paid_cents,
                'status'=>$i->status,'due_at'=>$i->due_at?->toIso8601String(),'paid_at'=>$i->paid_at?->toIso8601String(),
                'can_pay'=>!$i->subscription->isTerminal() && !in_array($i->status,['paid','cancelled','refunded','disputed'],true)
                    && ($i->payment_url || ($i->is_initial && $checkout->available($i->subscription))),
            ]),
            'proposals'=>$customer->proposals->whereNull('locked_at')->filter(fn($p)=>$p->expires_at->isFuture() && in_array($p->status,['draft','awaiting_acceptance'],true))->values()
                ->map(fn($p)=>['id'=>$p->id,'name'=>$p->productVersion->name,'pet_name'=>$p->pet->name,'pet_id'=>$p->pet_id,'version_id'=>$p->product_version_id]),
        ]);
    }

    public function resendVerification(Request $r)
    {
        $key='portal-verify:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key,1),429,'Aguarde um minuto antes de reenviar.');
        RateLimiter::hit($key,60);
        abort_unless($r->user()->email_verified_at || $this->sendVerification($r->user()),503,'Não foi possível enviar a confirmação. Tente novamente em instantes.');
        return response()->json(['message'=>'Confira seu e-mail para verificar o contato.']);
    }

    private function sendVerification(User $user): bool
    {
        // O servidor de e-mail às vezes recusa por instantes (ex.: 451 temporário): tenta até 3 vezes antes de desistir.
        for ($try=1;$try<=3;$try++) {
            try { $user->notify(new PortalVerifyEmail); return true; }
            catch (\Throwable $e) { if ($try<3) { sleep(2); continue; } Log::warning('portal.verification_pending',['user_id'=>$user->id,'error_type'=>$e::class]); }
        }
        return false;
    }

    // Link expirado ou e-mail não recebido: pede outro pelo e-mail, sem revelar se a conta existe.
    public function resendVerificationByEmail(Request $r)
    {
        $data=$r->validate(['email'=>'required|email|max:180']);
        $user=User::where('email',$data['email'])->where('role','customer')->where('active',true)->whereNull('email_verified_at')->first();
        $key='portal-verify:'.($user?->id ?? sha1($data['email']));
        if ($user && !RateLimiter::tooManyAttempts($key,1)) { RateLimiter::hit($key,60); $this->sendVerification($user); }
        return response()->json(['message'=>'Se o e-mail estiver cadastrado e ainda não confirmado, enviamos um novo link.']);
    }

    public function verifyEmail(Request $r,User $user,string $hash)
    {
        // O link assinado (válido por 1 hora e enviado só ao e-mail do cliente) basta: quem abre o e-mail pode estar em outro navegador.
        abort_unless($user->role==='customer' && $user->customer && hash_equals(sha1($user->email),$hash),403);
        DB::transaction(function() use($user) {
            $user->forceFill(['email_verified_at'=>now()])->save();
            $user->customer->update(['contact_verified_at'=>now()]);
        });
        app(\App\Services\Domain\ContactVerificationService::class)->activatePending($user->customer_id);
        return response()->json(['message'=>'E-mail confirmado. Seu plano já pode ser ativado.',
            'login_url'=>URL::temporarySignedRoute('login.confirmed',now()->addMinutes(5),['user'=>$user->id])]);
    }

    public function forgotPassword(Request $r)
    {
        $data=$r->validate(['email'=>'required|email|max:180']);
        $user=User::where('email',$data['email'])->where('role','customer')->where('active',true)->first();
        if ($user) {
            try {
                Password::sendResetLink($data,function($user,$token) {
                    $url=config('site_portal.public_url').'/area-do-cliente/redefinir-senha?'.http_build_query(['token'=>$token,'email'=>$user->email]);
                    $user->notify(new \App\Notifications\PortalPasswordReset($url));
                });
            } catch(\Throwable $e) { Log::warning('portal.password_mail_pending',['error_type'=>$e::class]); }
        }
        return response()->json(['message'=>'Se houver uma conta de cliente com esse e-mail, você receberá as instruções para redefinir a senha.']);
    }

    public function resetPassword(Request $r)
    {
        $data=$r->validate(['email'=>'required|email|max:180','token'=>'required|string|max:200','password'=>'required|string|min:12|max:1000|confirmed']);
        abort_unless(User::where('email',$data['email'])->where('role','customer')->where('active',true)->exists(),422,'Link inválido ou expirado.');
        $status=Password::reset($data,function($user,$password) {
            $user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();
            DB::table('portal_tokens')->where('user_id',$user->id)->delete();
            event(new PasswordReset($user));
        });
        abort_unless($status===Password::PASSWORD_RESET,422,'Link inválido ou expirado. Solicite outro link.');
        return response()->json(['message'=>'Senha atualizada. Entre com sua nova senha.']);
    }

    // A confirmação do e-mail não bloqueia mais assinar nem pagar: ela vem por último e libera a ativação.
    private function verified(Request $r): void {}
    private function owns(Request $r,object $record): void { abort_unless((int)$record->customer_id===(int)$r->user()->customer_id,404); }
    private function offered(int $id): ProductVersion { return ProductVersion::offered()->with('product')->findOrFail($id); }

    public function addPet(Request $r)
    {
        $this->verified($r);
        $data=$r->validate(['name'=>'required|string|max:100','species'=>'required|in:dog,cat','birth_date'=>'required|date|before_or_equal:today',
            'breed'=>'required|string|max:100','color'=>'required|string|max:100','sex'=>'required|in:male,female','neutered'=>'required|boolean'],
            ['neutered.required'=>'Informe se o pet é castrado.']);
        $pet=$r->user()->customer->pets()->create(['neutered'=>(bool)$data['neutered']]+$data);
        Audit::record('portal.pet_created',$pet,$r->user());
        return response()->json(['id'=>$pet->id,'message'=>'Pet cadastrado.'],201);
    }

    public function createProposal(Request $r,ProposalService $service)
    {
        $this->verified($r);
        $data=$r->validate(['pet_id'=>'required|integer','product_version_id'=>'required|integer']);
        $pet=$r->user()->customer->pets()->findOrFail($data['pet_id']);
        $version=$this->offered($data['product_version_id']);
        $unit=Franchise::where('code','CENTRAL')->where('active',true)->firstOrFail();
        app(\App\Services\Attribution\AttributionService::class)->resume($r,$r->input('attribution_ref'));
        $attribution=app(\App\Services\Attribution\AttributionService::class)->proposalData($r,[],$r->user());
        $p=$service->create(['customer_id'=>$r->user()->customer_id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>$unit->id,'channel'=>'site','prefill_by'=>'customer','signature_method'=>'digital'],$r->user(),$attribution);
        return response()->json(['id'=>$p->id],201);
    }

    public function proposal(Request $r,Proposal $proposal,ProposalService $service,VindiCheckoutService $checkout,ContractService $contracts,PdfPages $pages)
    {
        $this->verified($r); $this->owns($r,$proposal);
        $proposal=$service->refreshForReview($proposal);
        $s=$proposal->subscription;
        $doc=$s?->documents()->where('kind','final_contract')->where('status','valid')->latest('version')->first();
        // Páginas como imagem (celular não abre PDF dentro da página); se a conversão falhar, o site cai para o PDF.
        $reviewPages=null; $documentPages=null;
        try {
            if (!$proposal->accepted_at) $reviewPages=$pages->render($contracts->reviewPdf($proposal->snapshot))['count'];
            elseif ($doc) $documentPages=$pages->render(Storage::disk(config('integrations.contracts.disk','local'))->get($doc->storage_path))['count'];
        } catch(\Throwable $e) { Log::warning('portal.contract_pages_pending',['proposal_id'=>$proposal->id,'error_type'=>$e::class]); }
        return response()->json(['id'=>$proposal->id,'number'=>$proposal->number,'name'=>$proposal->productVersion->name,'pet_name'=>$proposal->pet->name,
            'signer_name'=>$proposal->customer->name,'price_cents'=>$proposal->price_cents,'billing_cycle'=>$proposal->productVersion->billing_cycle,
            'fingerprint'=>$service->reviewFingerprint($proposal),'accepted'=>(bool)$proposal->accepted_at,'expires_at'=>$proposal->expires_at->toIso8601String(),
            'available'=>!$proposal->locked_at && $proposal->expires_at->isFuture() && $proposal->productVersion->isOffered() && in_array($proposal->status,['draft','awaiting_acceptance'],true),
            'review_pages'=>$reviewPages,'document_pages'=>$documentPages,
            'subscription_id'=>$s?->id,'document_id'=>$doc?->id,'can_checkout'=>$s?$checkout->available($s):false,
            'payment_methods'=>collect($checkout->paymentMethods())->map(fn($label,$code)=>['code'=>$code,'label'=>$label,'card'=>str_contains($code,'credit_card')])->values(),
            'payment_method'=>$s?->payment_method_code]+$this->nextBilling($proposal));
    }

    /** Projected next monthly bill while the entry (first installment) is still to be paid. */
    private function nextBilling(Proposal $proposal): array
    {
        $initial=$proposal->subscription?->invoices()->where('is_initial',true)->first();
        if ($proposal->productVersion->billing_cycle!=='monthly' || $initial?->status==='paid') return [];
        $schedule=app(\App\Services\Domain\BillingSchedule::class);
        $next=$schedule->firstRecurringBilling($proposal->accepted_at ?? now(),null,(int)$proposal->free_months);
        return ['next_billing_at'=>$next->toDateString(),'next_due_at'=>$schedule->dueDate($next)->toDateString()];
    }

    public function reviewPdf(Request $r,Proposal $proposal,ProposalService $service,ContractService $contracts)
    {
        $this->verified($r); $this->owns($r,$proposal);
        $proposal=$service->refreshForReview($proposal);
        try { $bytes=$contracts->reviewPdf($proposal->snapshot); }
        catch(\Throwable $e) { Log::warning('portal.review_pdf_pending',['proposal_id'=>$proposal->id,'error_type'=>$e::class]); return response()->json(['message'=>'O contrato está sendo preparado. Tente abrir o PDF novamente.'],503); }
        DB::table('portal_tokens')->where('id',$r->attributes->get('portal_token_id'))->update([
            'review_proposal_id'=>$proposal->id,'review_fingerprint'=>$service->reviewFingerprint($proposal),'reviewed_at'=>now()]);
        return response($bytes)->header('Content-Type','application/pdf')->header('Content-Disposition','inline; filename="contrato-para-conferencia.pdf"');
    }

    /** Uma página do contrato para conferência, como JPEG. Ver a última página conta como contrato conferido (igual a abrir o PDF). */
    public function reviewPage(Request $r,Proposal $proposal,int $page,ProposalService $service,ContractService $contracts,PdfPages $pages)
    {
        $this->verified($r); $this->owns($r,$proposal);
        $proposal=$service->refreshForReview($proposal);
        $set=$pages->render($contracts->reviewPdf($proposal->snapshot));
        abort_unless($page>=1 && $page<=$set['count'],404);
        if ($page===$set['count']) DB::table('portal_tokens')->where('id',$r->attributes->get('portal_token_id'))->update([
            'review_proposal_id'=>$proposal->id,'review_fingerprint'=>$service->reviewFingerprint($proposal),'reviewed_at'=>now()]);
        return response()->file($pages->path($set['key'],$page),['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store']);
    }

    public function documentPage(Request $r,ContractDocument $document,int $page,ContractService $contracts,PdfPages $pages)
    {
        $this->verified($r); $this->owns($r,$document->subscription);
        abort_unless($document->kind==='final_contract' && $document->status==='valid' && $contracts->verifyIntegrity($document),404);
        $set=$pages->render(Storage::disk(config('integrations.contracts.disk','local'))->get($document->storage_path));
        abort_unless($page>=1 && $page<=$set['count'],404);
        return response()->file($pages->path($set['key'],$page),['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store']);
    }

    public function accept(Request $r,Proposal $proposal,ProposalService $service,ContractService $contracts,SigningEvidence $signing)
    {
        $this->verified($r); $this->owns($r,$proposal);
        if ($proposal->accepted_at && $proposal->subscription) return response()->json(['accepted'=>true,'id'=>$proposal->id]);
        $data=$r->validate(['accepted'=>'accepted','signer_name'=>'required|string|min:3|max:180','fingerprint'=>'required|string|size:64',
            'signature_data'=>'required|string|max:300000','device_info'=>'nullable|string|max:4000'],['signature_data.required'=>'Desenhe sua assinatura para confirmar o contrato.']);
        $signature=$contracts->signatureImage($data['signature_data']);
        $signatureHash=hash('sha256',base64_decode(substr($signature,22),true));
        $review=DB::table('portal_tokens')->where('id',$r->attributes->get('portal_token_id'))->first();
        abort_unless((int)$review->review_proposal_id===$proposal->id && $review->reviewed_at && now()->subHours(2)->lte($review->reviewed_at)
            && hash_equals((string)$review->review_fingerprint,$data['fingerprint']),409,'Abra o PDF e confira o contrato antes de assinar.');
        $normal=fn($s)=>Str::lower(Str::ascii(preg_replace('/\s+/u',' ',trim($s))));
        abort_unless($normal($data['signer_name'])===$normal($proposal->customer->name),422,'Informe o nome completo do titular cadastrado.');
        $service->accept($proposal,$r->user(),['accepted'=>true,'affirmative'=>true,'customer_acceptance'=>true,'signer_name'=>$data['signer_name'],
            'review_fingerprint'=>$data['fingerprint'],'session_hash'=>$r->attributes->get('portal_token_hash'),'source'=>'institutional_portal',
            'signature'=>$signatureHash,'signature_hash'=>$signatureHash,'signature_data'=>$signature]+$signing->collect($r,$data['device_info']??null,true));
        return response()->json(['accepted'=>true,'id'=>$proposal->id]);
    }

    public function generateDocument(Request $r,Proposal $proposal,ContractService $contracts)
    {
        $this->verified($r); $this->owns($r,$proposal);
        abort_unless($proposal->accepted_at && $proposal->subscription && $proposal->signature_method==='digital',422);
        try { $doc=$contracts->generateDigital($proposal->subscription,$proposal->acceptance_evidence); }
        catch(\Throwable $e) { Log::warning('portal.final_pdf_pending',['proposal_id'=>$proposal->id,'error_type'=>$e::class]); return response()->json(['message'=>'Sua assinatura está salva. O PDF ainda está em preparação. Tente novamente.'],503); }
        return response()->json(['ready'=>true,'document_id'=>$doc->id]);
    }

    public function document(Request $r,ContractDocument $document,ContractService $contracts)
    {
        $this->verified($r); $this->owns($r,$document->subscription);
        abort_unless($document->kind==='final_contract' && $document->status==='valid' && $contracts->verifyIntegrity($document),404);
        Audit::record('portal.contract_download',$document,$r->user());
        return response(Storage::disk(config('integrations.contracts.disk','local'))->get($document->storage_path))
            ->header('Content-Type','application/pdf')->header('Content-Disposition','inline; filename="contrato-'.$document->subscription->number.'.pdf"');
    }

    public function checkout(Request $r,Subscription $subscription,VindiCheckoutService $checkout)
    {
        $this->verified($r); $this->owns($r,$subscription);
        $r->validate(['payment_method'=>'nullable|string|max:60']);
        try { return response()->json(['url'=>$checkout->start($subscription,$r->input('payment_method'))]); }
        catch(IntegrationPendingException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

    public function invoicePayment(Request $r,Invoice $invoice,VindiCheckoutService $checkout)
    {
        $this->verified($r); $this->owns($r,$invoice);
        abort_if($invoice->subscription->isTerminal() || in_array($invoice->status,['paid','cancelled','refunded','disputed'],true),422,'Esta fatura não está disponível para pagamento.');
        if (!$invoice->payment_url && $invoice->is_initial) return $this->checkout($r,$invoice->subscription,$checkout);
        try { $url=$checkout->paymentUrl((string)$invoice->payment_url); }
        catch(\Throwable) { return response()->json(['message'=>'O pagamento está em conferência. Fale com sua unidade.'],422); }
        return response()->json(['url'=>$url]);
    }

    public function requestCancellation(Request $r,Subscription $subscription)
    {
        $this->verified($r); $this->owns($r,$subscription);
        $data=$r->validate(['reason'=>'required|string|min:5|max:1000']);
        abort_if($subscription->isTerminal(),422,'Este contrato já está encerrado.');
        $cancellation=DB::transaction(function() use($subscription,$r,$data) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            $current=Subscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($current->isTerminal(),422,'Este contrato já está encerrado.');
            return CancellationRequest::firstOrCreate(['subscription_id'=>$subscription->id],['requested_by'=>$r->user()->id,'reason'=>$data['reason'],'status'=>'pending']);
        },3);
        Audit::record('cancellation_requested',$cancellation,$r->user());
        return response()->json(['message'=>'Solicitação registrada. A unidade confirmará o encerramento e o tratamento das cobranças.']);
    }
}
