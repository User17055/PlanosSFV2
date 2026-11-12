<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Franchise, Pet, Proposal, ProductVersion, Subscription, User};
use App\Services\AccessService;
use App\Services\Contracts\{ContractService,SigningEvidence};
use App\Services\Domain\{ProposalService, CancellationService};
use App\Services\Attribution\{AttributionService,MarketingLinkService,TrackingSanitizer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProposalController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request)
    {
        $query = $request->user()->role === 'customer' ? Proposal::where('customer_id',$request->user()->customer_id) : $this->access->scope(Proposal::query(),$request->user());
        if ($request->filled('status')) $query->where('status',$request->string('status')->toString());
        if ($request->user()->role !== 'customer') {
            $filters=$request->validate(['q'=>'nullable|string|max:80','channel'=>'nullable|in:site,link,presential','origem'=>'nullable|in:'.implode(',',array_keys(TrackingSanitizer::sourceLabels())),'etapa'=>'nullable|in:'.implode(',',array_keys(Proposal::STAGES))]);
            if (filled($filters['q']??null)) {
                $like='%'.addcslashes($filters['q'],'%_\\').'%';
                $query->where(fn($w)=>$w->where('number','like',$like)->orWhereHas('customer',fn($c)=>$c->where('name','like',$like))->orWhereHas('pet',fn($p)=>$p->where('name','like',$like)));
            }
            if (filled($filters['channel']??null)) $query->where('channel',$filters['channel']);
            if (filled($filters['origem']??null)) $query->where('acquisition_source',$filters['origem']);
            if (filled($filters['etapa']??null)) $query->inStage($filters['etapa']);
        }
        $proposals = $query->with(['customer','pet','productVersion.product','originatingFranchise','subscription.invoices'])->latest()->paginate(15)->withQueryString();
        return view('proposals.index',compact('proposals'));
    }

    public function create(Request $request)
    {
        $request->validate(['customer_id'=>'nullable|integer|min:1','pet_id'=>'nullable|integer|min:1']);
        $selectedCustomer = $request->filled('customer_id') ? Customer::findOrFail($request->integer('customer_id')) : null;
        $selectedPet = $request->filled('pet_id') ? Pet::with('customer')->findOrFail($request->integer('pet_id')) : null;
        if ($selectedCustomer) $this->access->customer($request->user(), $selectedCustomer);
        if ($selectedPet) {
            $this->access->customer($request->user(), $selectedPet->customer);
            abort_if($selectedCustomer && (int)$selectedPet->customer_id !== (int)$selectedCustomer->id, 422, 'O pet deve pertencer ao tutor selecionado.');
            $selectedCustomer ??= $selectedPet->customer;
        }
        if(!$request->filled('product_version_id') && $request->session()->has('sf_suggested_product_version_id')) $request->merge(['product_version_id'=>$request->session()->get('sf_suggested_product_version_id')]);
        $customers = $this->access->customerQuery($request->user())->with('pets')->orderBy('name')->get();
        $versions = ProductVersion::with(['product','benefitRules'])->preferredOffers()->get();
        $franchises = Franchise::where('active',true)->when($request->user()->role !== 'central_admin' && $request->user()->role !== 'customer',fn ($q)=>$q->whereIn('id',$this->access->franchiseIds($request->user())))->get();
        if ($request->user()->role === 'customer') {
            $attribution=app(AttributionService::class)->proposalData($request,[],$request->user());
            $franchises=$attribution->originatingFranchiseId?$franchises->where('id',$attribution->originatingFranchiseId):$franchises->where('code','CENTRAL');
        }
        $referrers=$request->user()->role==='customer'?collect():$customers;
        $campaigns=$request->user()->role==='customer'?collect():\App\Models\Campaign::current()->orderBy('name')->get();
        return view('proposals.create',compact('customers','versions','franchises','referrers','selectedCustomer','selectedPet','campaigns'));
    }

    public function store(Request $request, ProposalService $service,AttributionService $attributionService)
    {
        $selfRegistration = $request->user()->role !== 'customer' && ($request->input('channel') === 'site'
            || ($request->input('channel') === 'link' && $request->input('prefill_by') === 'customer'));
        $data=$request->validate([
            'customer_id'=>$selfRegistration?'exclude':'required|integer|exists:customers,id','pet_id'=>$selfRegistration?'exclude':'required|integer|exists:pets,id',
            'product_version_id'=>'required|integer|exists:product_versions,id','originating_franchise_id'=>'required|integer|exists:franchises,id',
            'channel'=>'required|in:site,link,presential','prefill_by'=>'required|in:seller,customer','signature_method'=>'required|in:digital,tablet,paper',
            'requested_start_at'=>'nullable|date|after_or_equal:today',
            'campaign_id'=>$request->user()->role==='customer'?'exclude':'nullable|integer',
        ]);
        $manual=$request->validate(['acquisition_source'=>'nullable|in:direct,referral,shared_link,instagram,facebook,google,other','referrer_customer_id'=>'nullable|integer|exists:customers,id','referrer_name'=>'nullable|string|max:180','campaign_name'=>'nullable|string|max:120']);
        if(!empty($manual['referrer_customer_id'])) $this->access->customer($request->user(),\App\Models\Customer::findOrFail($manual['referrer_customer_id']));
        if ($selfRegistration) {
            $this->access->franchise($request->user(), (int)$data['originating_franchise_id']);
            $version = ProductVersion::findOrFail($data['product_version_id']);
            $created = app(MarketingLinkService::class)->create([
                'name'=>'Preenchimento · '.$version->name, 'originating_franchise_id'=>$data['originating_franchise_id'],
                'owner_id'=>$request->user()->id, 'product_version_id'=>$version->id, 'self_registration'=>true,
                'acquisition_source'=>in_array($manual['acquisition_source']??'', ['referral','shared_link','instagram','facebook','google','other']) ? $manual['acquisition_source'] : 'shared_link',
                'referrer_customer_id'=>$manual['referrer_customer_id']??null, 'referrer_name'=>$manual['referrer_name']??null,
                'campaign_name'=>$manual['campaign_name']??null, 'expires_at'=>now()->addHours((int)config('plans.proposal_validity_hours',48))->toIso8601String(),
                'campaign_id'=>$data['campaign_id']??null,
            ], $request->user());
            return redirect()->route('proposals.registration-link',$created['link']);
        }
        $customer=\App\Models\Customer::findOrFail($data['customer_id']);
        $this->access->customer($request->user(),$customer);
        if($request->user()->role!=='customer') {
            $fallback=array_intersect_key((array)$customer->acquisition_profile,array_flip(['acquisition_source','referrer_name','campaign_name']));
            if(($fallback['acquisition_source']??null)==='unknown')unset($fallback['acquisition_source']);
            $manual=array_replace($fallback,array_filter($manual,fn($value)=>filled($value)));
        }
        $attribution=$attributionService->proposalData($request,$manual,$request->user());
        if ($request->user()->role === 'customer') {
            $data['channel']='site'; $data['signature_method']='digital'; $data['prefill_by']='customer';
            $data['originating_franchise_id']=$attribution->originatingFranchiseId??Franchise::where('code','CENTRAL')->where('active',true)->firstOrFail()->id;
        } else $this->access->franchise($request->user(),(int)$data['originating_franchise_id']);
        $proposal=$service->create($data,$request->user(),$attribution);
        if ($data['channel']==='link') {
            $token=$service->issueLink($proposal);
            session()->flash('proposal_public_url',$url=route('sales.proposal',['token'=>$token]));
            if($this->emailLink($proposal,$url)) return redirect()->route('proposals.show',$proposal)->with('success','Proposta criada e link enviado para '.$proposal->customer->email.'. O aceite pertence ao titular.');
        }
        return redirect()->route('proposals.show',$proposal)->with('success','Proposta criada. O aceite pertence ao titular.');
    }

    public function registrationLink(Request $request, \App\Models\MarketingLink $link, MarketingLinkService $links)
    {
        $links->authorize($link, $request->user());
        abort_unless($link->self_registration,404);
        $url=route('attribution_links.visit',['token'=>$links->urlToken($link,$request->user())]);
        return view('proposals.registration-link',compact('link','url'));
    }

    public function show(Request $request, Proposal $proposal,ProposalService $service)
    {
        $this->access->record($request->user(),$proposal);
        $proposal=$service->refreshForReview($proposal);
        $proposal->load(['customer','pet','productVersion.product','productVersion.benefitRules','originatingFranchise','subscription.contractDocuments','subscription.invoices','documents']);
        $publicUrl=session('proposal_public_url');
        $reviewFingerprint=$service->reviewFingerprint($proposal);
        $nativeContract=(bool)data_get($proposal->snapshot,'version.contract_layout.source');
        $review=$request->session()->get('contract_review.'.$proposal->id,[]);
        $signatureReviewRequired=$nativeContract && (empty($review['fingerprint']) || !hash_equals($reviewFingerprint,$review['fingerprint']) || ($review['reviewed_at']??0)<now()->subHours(2)->timestamp);
        $signingAvailable=!$proposal->locked_at && in_array($proposal->status,['draft','awaiting_acceptance'],true) && $proposal->expires_at->isFuture() && $proposal->productVersion->isOffered();
        // O e-mail é confirmado depois do pagamento, como no site (ver ContactVerificationLastStepTest); a ativação é que espera por ele.
        $autoCheckout=$request->user()->role==='customer' && $proposal->subscription?->document_status==='valid' && $request->session()->pull('checkout_after_signing.'.$proposal->id,false);
        return view('proposals.show',compact('proposal','publicUrl','reviewFingerprint','nativeContract','signatureReviewRequired','signingAvailable','autoCheckout'));
    }

    public function link(Request $request, Proposal $proposal, ProposalService $service)
    {
        $this->access->record($request->user(),$proposal);
        $token=$service->issueLink($proposal);
        $url=route('sales.proposal',['token'=>$token]);
        $sent=$this->emailLink($proposal->fresh(),$url);
        return back()->with('proposal_public_url',$url)->with('success',$sent?'Link gerado e enviado para '.$proposal->customer->email.'. O link anterior foi substituído.':'Link gerado. O link anterior foi substituído.');
    }

    private function emailLink(Proposal $proposal,string $url): bool
    {
        $email=$proposal->customer->email;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return false;
        try { \Illuminate\Support\Facades\Notification::route('mail',$email)->notify(new \App\Notifications\ProposalLink($proposal->loadMissing(['customer','pet','productVersion']),$url)); return true; }
        catch(\Throwable $exception) { \Illuminate\Support\Facades\Log::warning('proposal_link_email_failed',['proposal_id'=>$proposal->id,'error_type'=>$exception::class,'reason'=>mb_substr($exception->getMessage(),0,300)]); return false; }
    }

    public function selfie(Request $request,Proposal $proposal)
    {
        $this->access->record($request->user(),$proposal);
        $path=data_get($proposal->acceptance_evidence,'selfie_path');
        $disk=\Illuminate\Support\Facades\Storage::disk(config('integrations.contracts.disk','local'));
        abort_unless($path && $disk->exists($path),404);
        $bytes=$disk->get($path);
        abort_unless(hash_equals((string)data_get($proposal->acceptance_evidence,'selfie_sha256'),hash('sha256',$bytes)),409,'Falha na integridade da foto registrada.');
        return response($bytes,200,['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function previewPdf(Request $request, Proposal $proposal, ProposalService $service)
    {
        $this->access->record($request->user(),$proposal);
        $proposal=$service->refreshForReview($proposal);
        $request->validate(['fingerprint'=>'nullable|string|size:64']);
        $fingerprint=$service->reviewFingerprint($proposal);
        if($request->filled('fingerprint') && !hash_equals($fingerprint,$request->input('fingerprint'))) throw new \App\Services\Domain\DomainException('PROPOSAL_REVIEW_REQUIRED','Os dados mudaram. Atualize esta página para conferir a versão atual do contrato.',409);
        $snapshot=$proposal->subscription?->contract_snapshot ?: $proposal->snapshot;
        try {
            $contracts=app(ContractService::class);
            $final=$proposal->subscription?->documents()->where('kind','final_contract')->where('status','valid')->latest('version')->first();
            if($final) {
                if(!$contracts->verifyIntegrity($final))throw new \RuntimeException('Falha na integridade do contrato final.');
                $bytes=\Illuminate\Support\Facades\Storage::disk(config('integrations.contracts.disk','local'))->get($final->storage_path);
              } else {
                  if($proposal->accepted_at)throw new \RuntimeException('O PDF final aguarda geração.');
                  $bytes=$contracts->reviewPdf($snapshot);
              }
        } catch(\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('contract_preview_unavailable',['proposal_id'=>$proposal->id,'error_type'=>$exception::class]);
            $message='Não foi possível abrir o PDF agora. Tente carregar novamente; seus dados e o aceite já registrado permanecem salvos.';
            return $request->expectsJson()?response()->json(['message'=>$message],503):response()->view('proposals.preview-unavailable',compact('proposal','message'),503);
        }
        $request->session()->put('contract_review.'.$proposal->id,['fingerprint'=>$fingerprint,'reviewed_at'=>now()->timestamp]);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="contrato-para-conferencia.pdf"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function publicProposal(Request $request,string $token)
    {
        abort_unless(strlen($token)>=32 && strlen($token)<=128,404);
        $proposal=Proposal::where('link_token_hash',hash('sha256',$token))->firstOrFail();
        // Sessão aberta pelo link de outro contrato (outro pet): recomeça pelo CPF deste link, em vez de cair na senha.
        if($request->user() && ($linked=$request->session()->get('signing_link_proposal')) && (int)$linked!==$proposal->id) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        if(!$request->user()) {
            $payment=$this->awaitingPayment($proposal);
            if($proposal->accepted_at && !$payment) return redirect()->route('login')->with('success','Este contrato já foi assinado. Para ver seu painel, entre com sua senha.');
            abort_unless($payment || $this->linkOpen($proposal),404);
            return view('proposals.link-identity',['token'=>$token,'signed'=>$payment]);
        }
        $completedOwn=$proposal->accepted_at && $request->user()->role==='customer' && (int)$request->user()->customer_id===(int)$proposal->customer_id;
        abort_unless($completedOwn || (!$proposal->link_revoked_at && $proposal->link_expires_at?->isFuture() && $proposal->status!=='cancelled'),404);
        $this->access->record($request->user(),$proposal);
        abort_unless($request->user()->role==='customer',403,'Entre com o acesso do titular para revisar e aceitar.');
        return redirect()->route('proposals.show',$proposal);
    }

    /** Confirma o CPF de quem recebeu o link e abre uma sessão que só alcança este contrato (ver ActiveUser). */
    public function confirmLink(Request $request,string $token)
    {
        abort_unless(strlen($token)>=32 && strlen($token)<=128,404);
        $proposal=Proposal::with('customer')->where('link_token_hash',hash('sha256',$token))->firstOrFail();
        abort_unless($this->linkOpen($proposal) || $this->awaitingPayment($proposal),404);
        if($request->user()) return redirect()->route('sales.proposal',$token);
        $data=$request->validate(['document'=>'required|string|max:20'],['document.required'=>'Informe o CPF do titular.']);
        $key='signing-link:'.hash('sha256',$token);
        if(RateLimiter::tooManyAttempts($key,5)) throw ValidationException::withMessages(['document'=>'Muitas tentativas. Aguarde alguns minutos ou fale com a unidade.']);
        $cpf=preg_replace('/\D/','',$data['document']);
        if($cpf==='' || !hash_equals((string)$proposal->customer->document,$cpf)) {
            RateLimiter::hit($key,900);
            throw ValidationException::withMessages(['document'=>'Este CPF não é o do titular do contrato. Confira os números.']);
        }
        RateLimiter::clear($key);
        $user=$proposal->customer->accessUser();
        if(!$user) throw ValidationException::withMessages(['document'=>'Não foi possível abrir o contrato por este link. Fale com a unidade.']);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('signing_link_proposal',$proposal->id);
        // Receber o link pessoal e conhecer o CPF confirma o contato do titular, como a assinatura exige.
        if(!$proposal->customer->contact_verified_at) $proposal->customer->update(['contact_verified_at'=>now()]);
        \App\Services\Domain\Audit::record('proposal.signing_link_opened',$proposal,$user);
        return redirect()->route('proposals.show',$proposal);
    }

    /** Assinado pelo link e com a entrada ainda por pagar: o mesmo link abre o contrato para pagar, sem senha. */
    private function awaitingPayment(Proposal $proposal): bool
    {
        $subscription=$proposal->accepted_at && $proposal->signature_method==='digital' && $proposal->status!=='cancelled' ? $proposal->subscription : null;
        return $subscription && !$subscription->isTerminal()
            && $subscription->invoices()->where('is_initial',true)->whereNotIn('status',['paid','cancelled','refunded'])->exists();
    }

    private function linkOpen(Proposal $proposal): bool
    {
        return !$proposal->link_revoked_at && $proposal->link_expires_at?->isFuture() && $proposal->status!=='cancelled' && $proposal->signature_method==='digital';
    }

    /** A assinatura precisa de um acesso do titular. Quem não tem ganha um com senha aleatória, que só é usado pelo link. */
    public function accept(Request $request, Proposal $proposal, ProposalService $service, ContractService $contracts, SigningEvidence $signing)
    {
        $this->access->record($request->user(),$proposal);
        abort_unless($request->user()->role==='customer' && (int)$request->user()->customer_id===(int)$proposal->customer_id,403,'O aceite digital deve ser realizado pelo titular autenticado.');
        $data=$request->validate(['accepted'=>'accepted','review_fingerprint'=>'required|string|size:64','signature_data'=>'required|string|max:300000','device_info'=>'nullable|string|max:4000'],
            ['signature_data.required'=>'Desenhe sua assinatura para confirmar o contrato.']);
        $this->validateSigner($request,$proposal);
        $signature=$contracts->signatureImage($data['signature_data']);
        $this->requireReview($request,$proposal);
        $evidence=['accepted'=>true,'affirmative'=>true,'customer_acceptance'=>true,'signer_name'=>$proposal->customer->name,'session_hash'=>hash('sha256',$request->session()->getId()),'contact_verified'=>(bool)$proposal->customer->contact_verified_at]
            +$this->signatureEvidence($signature)+$signing->collect($request,$data['device_info']??null);
        $evidence['review_fingerprint']=$request->input('review_fingerprint');
        if((int)$request->session()->get('signing_link_proposal')===$proposal->id) $evidence['source']='signature_link';
        $subscription=$service->accept($proposal,$request->user(),$evidence);
        $request->session()->put('checkout_after_signing.'.$proposal->id,true);
        if(data_get($subscription->contract_snapshot,'version.contract_layout.source')) return redirect()->route('proposals.show',$proposal)->with('success','Assinatura registrada. Seu PDF será preparado nesta tela; você não precisa assinar novamente.');
        if(!$this->generateContract($contracts,$subscription,$evidence+['signature_method'=>'digital'])) return redirect()->route('proposals.show',$proposal)->with('success','Assinatura registrada. Estamos preparando seu PDF; você não precisa assinar novamente.');
        return redirect()->route('proposals.show',$proposal)->with('success','Aceite registrado e contrato final gerado. A ativação depende da confirmação financeira e das demais verificações.');
    }

    public function paper(Request $request,Proposal $proposal,ProposalService $service,ContractService $contracts)
    {
        $this->access->record($request->user(),$proposal);
        abort_unless(config('plans.demo') || config('plans.paper_enabled'),422,'A operação presencial em papel depende da aprovação das regras e validadores.');
        $request->validate(['review_fingerprint'=>'required|string|size:64']);
        $subscription=$service->issuePaper($proposal,$request->user(),['review_fingerprint'=>$request->input('review_fingerprint')]);
        try { $contracts->generatePaperDraft($subscription,$request->user()->id); }
        catch(\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('paper_draft_pending',['subscription_id'=>$subscription->id,'error_type'=>$e::class]);
            return back()->with('error','A reserva foi preservada, mas o PDF não ficou pronto. Use “Tentar emitir PDF novamente” para recuperar a minuta.');
        }
        return back()->with('success','Vaga reservada e condições congeladas. Imprima a minuta, colha a assinatura e anexe a cópia final para conferência.');
    }

    public function tablet(Request $request,Proposal $proposal,ProposalService $service,ContractService $contracts,SigningEvidence $signing)
    {
        $this->access->record($request->user(),$proposal);
        abort_unless($proposal->channel==='presential' && $proposal->signature_method==='tablet',422);
        $data=$request->validate(['accepted'=>'accepted','signer_name'=>'required|string|min:3|max:180','signature_data'=>'required|string|max:300000','review_fingerprint'=>'required|string|size:64',
            'selfie_data'=>'required|string|max:900000','device_info'=>'nullable|string|max:4000'],['selfie_data.required'=>'Tire uma foto do rosto do titular para concluir a assinatura presencial.']);
        $this->validateSigner($request,$proposal);
        $data['signature_data']=$contracts->signatureImage($data['signature_data']);
        $this->requireReview($request,$proposal);
        $selfie=$signing->storeSelfie($data['selfie_data'],$proposal->id);
        $evidence=['accepted'=>true,'affirmative'=>true,'customer_acceptance'=>true,'signer_name'=>$data['signer_name'],'session_hash'=>hash('sha256',$request->session()->getId()),'collected_by'=>$request->user()->id]
            +$this->signatureEvidence($data['signature_data'])+$selfie+$signing->collect($request,$data['device_info']??null);
        $evidence['review_fingerprint']=$data['review_fingerprint'];
        try { $subscription=$service->accept($proposal,$request->user(),$evidence); }
        catch(\Throwable $exception) { $signing->discardSelfie($selfie); throw $exception; }
        if(data_get($subscription->contract_snapshot,'version.contract_layout.source')) return redirect()->route('proposals.show',$proposal)->with('success','Assinatura registrada. Seu PDF será preparado nesta tela; você não precisa assinar novamente.');
        if(!$this->generateContract($contracts,$subscription,$evidence+['signature_method'=>'tablet'])) return back()->with('success','Assinatura registrada. Estamos preparando seu PDF; você não precisa assinar novamente.');
        return back()->with('success','Assinatura presencial registrada e contrato final gerado.');
    }

    public function regenerate(Request $request,Proposal $proposal,ContractService $contracts)
    {
        $this->access->record($request->user(),$proposal);
        abort_unless($proposal->locked_at && $proposal->accepted_at && $proposal->subscription && in_array($proposal->signature_method,['digital','tablet'],true),422,'Esta contratação exige outro fluxo documental.');
        $evidence=$proposal->acceptance_evidence;
        abort_unless(is_array($evidence) && ($evidence['affirmative']??false)===true,422,'Evidências originais do aceite indisponíveis para reprocessar.');
        $ok=$this->generateContract($contracts,$proposal->subscription,$evidence+['signature_method'=>$proposal->signature_method]);
          if($request->expectsJson()) {
              $document=$proposal->subscription->documents()->where('kind','final_contract')->where('status','valid')->first();
              return response()->json(['ready'=>$ok && (bool)$document,'download_url'=>$ok && $document?route('contracts.download',$document):null,
                  'message'=>$ok?'Assinatura concluída. Seu contrato está disponível.':'Sua assinatura está registrada. O PDF ainda está em preparação.'], $ok?200:503);
          }
        return back()->with($ok?'success':'error',$ok?'Contrato gerado a partir do aceite e das condições originais.':'O documento permanece pendente. Solicite apoio à administração.');
    }

    private function signatureEvidence(string $signature): array
    {
        $hash=hash('sha256',base64_decode(substr($signature,22),true));
        return ['signature'=>$hash,'signature_hash'=>$hash,'signature_data'=>$signature];
    }

    private function generateContract(ContractService $contracts,Subscription $subscription,array $evidence): bool
    {
        try { $contracts->generateDigital($subscription,$evidence); return true; }
        catch(\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('contract_generation_pending',['subscription_id'=>$subscription->id,'error_type'=>$exception::class]);
            return false;
        }
    }

    private function validateSigner(Request $request,Proposal $proposal): void
    {
        $request->validate(['signer_name'=>'sometimes|required|string|min:3|max:180']);
        $normalize=fn($name)=>\Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(preg_replace('/\s+/u',' ',trim($name))));
        if($request->has('signer_name') && $normalize($request->input('signer_name'))!==$normalize($proposal->customer->name)) throw \Illuminate\Validation\ValidationException::withMessages(['signer_name'=>'Confirme o nome completo do titular cadastrado. Para corrigir o nome, atualize o cadastro antes de assinar.']);
    }

    private function requireReview(Request $request,Proposal $proposal): void
    {
        if($proposal->locked_at || !data_get($proposal->snapshot,'version.contract_layout.source'))return;
        $review=$request->session()->get('contract_review.'.$proposal->id,[]);
        if(empty($review['fingerprint']) || ($review['reviewed_at']??0)<now()->subHours(2)->timestamp || !hash_equals($review['fingerprint'],(string)$request->input('review_fingerprint'))) throw \Illuminate\Validation\ValidationException::withMessages(['contract_review'=>'Carregue o contrato em PDF para conferência antes de confirmar a assinatura.']);
    }

    public function cancel(Request $request,Proposal $proposal)
    {
        $this->access->record($request->user(),$proposal);
        abort_if($proposal->locked_at,409,'A proposta já foi formalizada. Utilize o encerramento da assinatura.');
        $proposal->update(['status'=>'cancelled','link_revoked_at'=>now()]);
        return back()->with('success','Proposta cancelada.');
    }

    public function cancelSubscription(Request $request,Subscription $subscription,CancellationService $service)
    {
        $this->access->record($request->user(),$subscription);
        $data=$request->validate(['reason'=>'required|string|min:5|max:1000','effective_at'=>'nullable|date']);
        $effective=null;
        if(!empty($data['effective_at'])) {
            $effective=\Carbon\CarbonImmutable::parse($data['effective_at'],'America/Sao_Paulo')->utc();
            abort_unless($effective->isFuture(),422,'O encerramento agendado deve estar no futuro.');
        }
        $service->cancel($subscription,$request->user(),$data['reason'],$effective?->toIso8601String());
        \App\Models\CancellationRequest::where('subscription_id',$subscription->id)->where('status','pending')->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>$request->user()->id]);
        if($request->integer('profile_cancel_subscription_id')===(int)$subscription->id) return redirect()->to(route('customers.show',$subscription->customer_id).'#assinatura-'.$subscription->id)->with('success','Encerramento registrado. O contrato permanece disponível no histórico desta ficha.');
        return back()->with('success','Encerramento registrado. As obrigações financeiras e o histórico foram preservados.');
    }

    public function requestCancellation(Request $request,Subscription $subscription)
    {
        $this->access->record($request->user(),$subscription);
        $data=$request->validate(['reason'=>'required|string|min:5|max:1000']);
        abort_if($subscription->isTerminal(),409,'Este vínculo já foi encerrado.');
        \Illuminate\Support\Facades\DB::transaction(function()use($request,$subscription,$data) {
            $locked=Subscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($locked->isTerminal(),409,'Este vínculo já foi encerrado.');
            $cancellation=\App\Models\CancellationRequest::firstOrCreate(['subscription_id'=>$subscription->id],['requested_by'=>$request->user()->id,'reason'=>$data['reason']]);
            if($cancellation->wasRecentlyCreated) \App\Models\AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$subscription->originating_franchise_id,'action'=>'cancellation.requested','entity_type'=>'subscription','entity_id'=>$subscription->id,'reason'=>$data['reason']]);
        });
        return back()->with('success','Solicitação registrada para a unidade responsável. A equipe confirmará a data efetiva e o tratamento das cobranças conforme o contrato.');
    }
}
