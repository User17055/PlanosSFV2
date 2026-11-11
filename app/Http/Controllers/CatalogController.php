<?php

namespace App\Http\Controllers;

use App\Models\{Product,ProductVersion};
use App\Services\Domain\CatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $realPlans=ProductVersion::where('is_demo',false)->whereNotNull('contract_layout')->exists();
        $products=Product::query()->when($realPlans && !$request->boolean('demonstracao'),fn($q)=>$q->whereHas('versions',fn($v)=>$v->where('is_demo',false)))
            ->with(['versions'=>fn($v)=>$v->when($realPlans && !$request->boolean('demonstracao'),fn($v)=>$v->where('is_demo',false))->with('benefitRules')])->orderBy('name')->get();
        $campaigns=$request->user()->role==='central_admin' ? \App\Models\Campaign::orderByDesc('starts_on')->orderByDesc('id')->get() : collect();
        return view('catalog.index',compact('products','campaigns'));
    }

    public function subscribe(Request $request, string $code)
    {
        $version=ProductVersion::preferredOffers()->whereHas('product',fn($q)=>$q->where('code',$code))->orderByDesc('version')->first();
        if (!$version) return redirect()->route('sales')->with('error','Este plano não está disponível para contratação. Confira os planos disponíveis.');
        $request->session()->put('sf_suggested_product_version_id',$version->id);
        return redirect()->route($request->user()?'proposals.create':'register',['product_version_id'=>$version->id,'channel'=>'site']);
    }

    public function create() { return view('catalog.create'); }

    public function previewTemplate(ProductVersion $version)
    {
        $text = $version->contract_template;
        return view('catalog.template-preview',compact('version','text'));
    }

    public function templatePdf(ProductVersion $version, \App\Services\Contracts\ContractService $contracts)
    {
        return response($contracts->templatePdf($version),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="modelo-contrato-'.$version->id.'.pdf"','Cache-Control'=>'private, no-store']);
    }

    public function offeredContract(ProductVersion $version, \App\Services\Contracts\ContractService $contracts)
    {
        abort_unless($version->isOffered(),404);
        return $this->templatePdf($version,$contracts);
    }

    public function edit(ProductVersion $version)
    {
        abort_if($version->published_at,409,'Versão publicada é imutável. Crie uma nova versão.');
        $version->load(['product','benefitRules']);
        return view('catalog.create',compact('version'));
    }

    public function updateVersion(Request $request,ProductVersion $version)
    {
        $data=$request->validate([
            'version_name'=>'required|string|max:150','price'=>'required|string|regex:/^\d{1,7}([,.]\d{1,2})?$/',
            'billing_cycle'=>'required|in:monthly,annual,one_time','duration_months'=>'nullable|integer|min:1|max:120',
            'species'=>'required|array|min:1','species.*'=>'in:dog,cat','contract_template'=>'required|string|min:100|max:100000',
            'benefits'=>'required|array|min:1|max:50','benefits.*.code'=>'required|string|max:50|distinct',
            'benefits.*.name'=>'required|string|max:150','benefits.*.waiting_days'=>'required|integer|min:0|max:3650',
            'benefits.*.quantity_limit'=>'nullable|integer|min:1|max:100000','benefits.*.period_type'=>'required|in:calendar_month,monthly_cycle,contract_year,lifetime',
        ]);
        $price=explode('.',str_replace(',','.',$data['price']));
        app(\App\Services\Contracts\ContractTemplate::class)->validate($data['contract_template']);
        $cents=(int)$price[0]*100+(int)str_pad($price[1]??'',2,'0');
        abort_if($cents<1,422,'O preço deve ser maior que zero.');
        DB::transaction(function()use($version,$data,$cents) {
            $locked=ProductVersion::lockForUpdate()->findOrFail($version->id);
            abort_if($locked->published_at,409,'Versão publicada é imutável.');
            $locked->update(['name'=>$data['version_name'],'price_cents'=>$cents,'billing_cycle'=>$data['billing_cycle'],'duration_months'=>$data['duration_months']??null,'species'=>$data['species'],'contract_template'=>$data['contract_template']]);
            $previousRules = $locked->benefitRules()->get()->keyBy('code');
            $locked->benefitRules()->delete();
            foreach($data['benefits'] as $rule) {
                $previous = $previousRules->get($rule['code']);
                $attributes = $previous ? collect($previous->getAttributes())->except(['id','product_version_id','created_at','updated_at'])->all() : ['unit'=>'procedimento','coverage_type'=>'included'];
                $locked->benefitRules()->create(array_merge($attributes,collect($rule)->only(['code','name','waiting_days','quantity_limit','period_type'])->all()));
            }
        });
        return redirect()->route('catalog.index')->with('success','Rascunho atualizado. As versões publicadas e os contratos permanecem preservados.');
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'name'=>'required|string|max:150','code'=>'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/|unique:products,code','category'=>'required|in:health_plan,package',
            'version_name'=>'required|string|max:150','price'=>'required|string|regex:/^\d{1,7}([,.]\d{1,2})?$/',
            'billing_cycle'=>'required|in:monthly,annual,one_time','duration_months'=>'nullable|integer|min:1|max:120',
            'species'=>'required|array|min:1','species.*'=>'in:dog,cat','contract_template'=>'required|string|min:100|max:100000',
            'benefits'=>'required|array|min:1|max:50','benefits.*.code'=>'required|string|max:50|distinct',
            'benefits.*.name'=>'required|string|max:150','benefits.*.waiting_days'=>'required|integer|min:0|max:3650',
            'benefits.*.quantity_limit'=>'nullable|integer|min:1|max:100000','benefits.*.period_type'=>'required|in:calendar_month,monthly_cycle,contract_year,lifetime',
        ]);
        $price=explode('.',str_replace(',','.',$data['price']));
        app(\App\Services\Contracts\ContractTemplate::class)->validate($data['contract_template']);
        $priceCents=(int)$price[0]*100+(int)str_pad($price[1]??'',2,'0');
        abort_if($priceCents<1,422,'O preço deve ser maior que zero.');
        DB::transaction(function() use($data,$priceCents) {
            $product=Product::create(['name'=>$data['name'],'code'=>strtoupper($data['code']),'category'=>$data['category']]);
            $version=$product->versions()->create(['name'=>$data['version_name'],'version'=>1,'price_cents'=>$priceCents,'billing_cycle'=>$data['billing_cycle'],'duration_months'=>$data['duration_months']??null,'renewal_policy'=>'none','species'=>$data['species'],'contract_template'=>$data['contract_template'],'status'=>'draft','is_demo'=>config('plans.demo')]);
            foreach($data['benefits'] as $benefit) $version->benefitRules()->create(collect($benefit)->only(['code','name','waiting_days','quantity_limit','period_type'])->all()+['unit'=>'procedimento','coverage_type'=>'included']);
        });
        return redirect()->route('catalog.index')->with('success','Produto criado em rascunho. Confira as condições e publique quando estiverem aprovadas.');
    }

    public function publish(ProductVersion $version,Request $request,CatalogService $service)
    {
        $service->publish($version,$request->user());
        return back()->with('success','Versão publicada e condições congeladas para novas contratações.');
    }

    public function cloneVersion(ProductVersion $version)
    {
        DB::transaction(function() use($version) {
            Product::lockForUpdate()->findOrFail($version->product_id);
            $next=$version->replicate(['published_at','published_by','offer_starts_at','offer_ends_at']);
            $next->version=$version->product->versions()->max('version')+1;
            $next->status='draft'; $next->save();
            foreach($version->benefitRules as $rule) $next->benefitRules()->create(collect($rule->attributesToArray())->except(['id','product_version_id','created_at','updated_at'])->all());
        });
        return back()->with('success','Nova versão criada em rascunho. Os contratos anteriores foram preservados.');
    }
}
