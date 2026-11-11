<?php

namespace App\Http\Controllers;

use App\Models\{Customer,Franchise,MarketingLink,ProductVersion,User};
use App\Services\AccessService;
use App\Services\Attribution\{AttributionService,MarketingLinkService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingLinkController extends Controller
{
    public function index(Request $request,MarketingLinkService $links,AccessService $access)
    {
        $user=$request->user();
        $marketingLinks=$links->scopeFor($user)->with(['owner','originatingFranchise','productVersion.product'])->orderByDesc('id')->paginate(15)->withQueryString();
        $urls=[]; $activity=[];
        foreach($marketingLinks as $link) {
            $urls[$link->id]=route('attribution_links.visit',['token'=>$links->urlToken($link,$user)]);
            $proposals=$access->scope(\App\Models\Proposal::where('marketing_link_id',$link->id),$user);
            $activity[$link->id]=[
                'accesses'=>DB::table('attribution_touches')->where('marketing_link_id',$link->id)->count(),
                'proposals'=>(clone $proposals)->count(),
                'accepted'=>(clone $proposals)->whereNotNull('accepted_at')->count(),
                'active'=>$access->scope(\App\Models\Subscription::where('marketing_link_id',$link->id),$user)->where('status','active')->count(),
            ];
        }
        $franchises=Franchise::where('active',true)->when($user->role!=='central_admin',fn($q)=>$q->whereIn('id',$access->franchiseIds($user)))->orderBy('name')->get();
        $owners=User::where('active',true)->whereIn('role',['central_admin','manager','sales'])->when($user->role==='sales',fn($q)=>$q->whereKey($user->id))->when($user->role!=='central_admin',fn($q)=>$q->whereHas('franchises',fn($f)=>$f->whereIn('franchises.id',$access->franchiseIds($user))->where('user_franchises.active',true)))->orderBy('name')->get();
        $versions=ProductVersion::preferredOffers()->with('product')->orderBy('name')->get();
        $referrers=$access->customerQuery($user)->select(['id','name'])->orderBy('name')->get();
        // Controle da campanha de cada link: o de autocadastro leva a do vendedor; os demais seguem a do site todo.
        $campaigns=\App\Models\Campaign::whereIn('id',$marketingLinks->pluck('campaign_id')->filter())->get()->keyBy('id');
        $siteCampaign=\App\Models\Campaign::current()->where('whole_site',true)->orderByDesc('free_months')->first();
        $used=\App\Models\Proposal::whereIn('marketing_link_id',$marketingLinks->where('self_registration',true)->pluck('id'))->whereNotNull('accepted_at')->pluck('marketing_link_id')->flip();
        return view('marketing.index',compact('marketingLinks','urls','activity','franchises','owners','versions','referrers','campaigns','siteCampaign','used'));
    }

    public function store(Request $request,MarketingLinkService $links,AccessService $access)
    {
        $data=$request->validate([
            'name'=>'required|string|min:3|max:120','acquisition_source'=>'required|in:referral,shared_link,instagram,facebook,google,other',
            'owner_id'=>'required|integer|exists:users,id','originating_franchise_id'=>'required|integer|exists:franchises,id',
            'product_version_id'=>'nullable|integer|exists:product_versions,id','referrer_customer_id'=>'nullable|integer|exists:customers,id',
            'referrer_name'=>'nullable|string|max:150','campaign_name'=>'nullable|string|max:120',
            'utm_source'=>'nullable|string|max:120','utm_medium'=>'nullable|string|max:120','utm_campaign'=>'nullable|string|max:120',
            'utm_content'=>'nullable|string|max:120','utm_term'=>'nullable|string|max:120','expires_at'=>'nullable|date',
        ]);
        $access->franchise($request->user(),(int)$data['originating_franchise_id']);
        if(!empty($data['referrer_customer_id'])) $access->customer($request->user(),Customer::findOrFail($data['referrer_customer_id']));
        if(!empty($data['expires_at'])) {
            $expiry=\Carbon\CarbonImmutable::parse($data['expires_at'],'America/Sao_Paulo')->utc();
            abort_unless($expiry->isFuture(),422,'A validade do link deve estar no futuro.');
            $data['expires_at']=$expiry->toIso8601String();
        }
        $result=$links->create($data,$request->user());
        return redirect()->route('attribution_links.index')->with('success','Link de divulgação criado. Copie o endereço para compartilhar; as novas contratações poderão registrar esta origem.');
    }

    public function revoke(Request $request,MarketingLink $link,MarketingLinkService $links)
    {
        $links->revoke($link,$request->user());
        return back()->with('success','Link desativado. Acessos e contratações anteriores foram preservados.');
    }

    public function visit(Request $request,string $token,MarketingLinkService $links,AttributionService $attribution)
    {
        // Link já usado: quem se cadastrou por ele volta para a própria conta; os demais veem o aviso.
        $known=preg_match('/^[A-Za-z0-9]{64}$/D',$token) ? \App\Models\MarketingLink::where('token_hash',hash('sha256',$token))->first() : null;
        if ($known && $links->used($known)) {
            $owner=\App\Models\Proposal::where('marketing_link_id',$known->id)->whereNotNull('accepted_at')->value('customer_id');
            if ($request->user()?->role==='customer' && (int)$request->user()->customer_id===(int)$owner) return redirect()->route('portal');
            return response()->view('marketing.link-used',[],410);
        }
        $link=$links->resolve($token);
        if ($link->self_registration && $link->product_version_id && $request->user() && $request->user()->role!=='customer') {
            $links->authorize($link,$request->user());
            return redirect()->route('attribution_links.contract-preview',['token'=>$token]);
        }
        if(!$request->user() || $request->user()->role==='customer') $attribution->capture($request,$link);
        $request->session()->put('sf_skip_attribution_landing',true);
        if($link->product_version_id) $request->session()->put('sf_suggested_product_version_id',$link->product_version_id);
        if ($link->self_registration && $link->product_version_id && (!$request->user() || $request->user()->role==='customer')) {
            // Sem login, o cadastro é o mesmo de Planos → Contratar no site. A origem vai junto, para a venda continuar com quem mandou o link.
            $code=$link->productVersion?->product?->code;
            if (!$request->user() && config('site_portal.enabled') && $code) {
                return redirect()->away(config('site_portal.public_url').'/contratar/'.rawurlencode($code).'?ref='.rawurlencode($attribution->handoff($request)));
            }
            return redirect()->route($request->user() ? 'proposals.create' : 'register', ['product_version_id'=>$link->product_version_id]);
        }
        return redirect()->route('sales',array_filter(['product_version_id'=>$link->product_version_id]));
    }

    public function contractPreview(Request $request,string $token,MarketingLinkService $links)
    {
        $link=$this->previewLink($request,$token,$links);
        $version=$link->productVersion;
        $signerName='{{contratante_nome}}';
        $petName='{{pet_nome}}';
        $preview=true;
        return view('marketing.contract-preview',compact('link','version','token','signerName','petName','preview'));
    }

    public function contractPreviewPdf(Request $request,string $token,MarketingLinkService $links,\App\Services\Contracts\ContractService $contracts)
    {
        $link=$this->previewLink($request,$token,$links);
        return response($contracts->templatePdf($link->productVersion),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="contrato-para-conferencia.pdf"','Cache-Control'=>'private, no-store']);
    }

    private function previewLink(Request $request,string $token,MarketingLinkService $links): MarketingLink
    {
        $link=$links->resolve($token);
        $links->authorize($link,$request->user());
        abort_unless($link->self_registration && $link->productVersion?->isOffered(),422,'Este link não possui plano disponível para preenchimento.');
        return $link;
    }
}
