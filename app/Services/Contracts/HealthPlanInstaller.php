<?php

namespace App\Services\Contracts;

use App\Models\{Product, ProductVersion};
use Illuminate\Support\Facades\DB;

class HealthPlanInstaller
{
    public const PLANS = ['super-economico'=>['Super Econômico','SF-SUPER-ECONOMICO',5200], 'economico'=>['Econômico','SF-ECONOMICO',8600], 'essencial'=>['Essencial','SF-ESS',28200], 'prime'=>['Prime','SF-PRIME',33000]];

    public function installOne(string $slug): ProductVersion
    {
        return DB::transaction(function() use($slug) {
            [$name,$code,$price]=self::PLANS[$slug];
            $text=file_get_contents(resource_path('contracts/plano-'.$slug.'.txt'));
            $layout=json_decode(file_get_contents(resource_path('contracts/plano-'.$slug.'.json')),true,512,JSON_THROW_ON_ERROR);
            app(ContractTemplate::class)->validate($text);
            $product=Product::firstOrCreate(['code'=>$code],['name'=>$name,'category'=>'health_plan']);
            $same=$product->versions()->where('contract_template',$text)->whereNotNull('published_at')->first();
            if ($same && $same->contract_layout===$layout) return $same;
            $version=$product->versions()->whereNull('published_at')->where('is_demo',false)->latest('version')->first();
            $attributes=['name'=>'Plano '.$name,'price_cents'=>$price,'billing_cycle'=>'monthly','duration_months'=>12,'renewal_policy'=>'automatic','species'=>['dog','cat'],'contract_template'=>$text,'contract_layout'=>$layout,'is_demo'=>false,'status'=>'draft','description'=>'Contrato original do Plano '.$name.' com preenchimento automático dos dados do tutor e do pet.'];
            if ($version) { $version->update($attributes); $version->benefitRules()->delete(); }
            else $version=$product->versions()->create($attributes+['version'=>1+(int)$product->versions()->max('version')]);
            $rules=[];
            $add=function($code,$name,$limit=null,$wait=0,$period='contract_year',$description=null,$manual=false) use (&$rules) {
                $rules[]=['code'=>$code,'name'=>$name,'quantity_limit'=>$limit,'waiting_days'=>$wait,'period_type'=>$period,'description'=>$description,'coverage_type'=>'included','requires_authorization'=>$manual];
            };
            $discount=function($code,$name,$percentage,$wait=30,$manual=false) use (&$rules) {
                $rules[]=['code'=>$code,'name'=>$name,'discount_basis_points'=>$percentage*100,'waiting_days'=>$wait,'period_type'=>'contract_year','coverage_type'=>'discount','requires_authorization'=>$manual];
            };
            $add('CONSULTA','Consultas em horário comercial (exceto especialistas)',match($slug){'prime'=>null,'essencial'=>10,default=>6});
            $add('PLANTAO','Consultas de plantão',2);
            $discount('DESC_PLANTAO','Demais consultas de plantão',30);
            $add('VACINA','Vacinas conforme contrato',1,30,'calendar_month',$slug==='super-economico'?'Uma dose de cada vacina relacionada no contrato; no máximo uma por mês. Conferir histórico de doses.':'Todas as doses das vacinas relacionadas no contrato; no máximo uma por mês.',$slug==='super-economico');
            if ($slug==='super-economico') {
                $discount('DESC_VACINA','Demais doses das vacinas',30);
                $add('HEMOGRAMA','Hemograma a cada seis meses',2,30,'contract_year','Um hemograma a cada seis meses, totalizando dois no plano. Conferir intervalo semestral antes de autorizar.',true);
            } elseif($slug==='economico') {
                $add('HEMOGRAMA','Hemogramas',2,30);
                foreach([['DESC_BIOQ','Exames bioquímicos realizados no hospital',10],['DESC_ULTRA_ELETRO','Ultrassom e eletro',10],['DESC_RAIOX','Raio-x',20],['DESC_CIRURGIA','Cirurgias',10],['DESC_INTERNACAO','Internação e UTI',10],['DESC_CELULAS','Terapia com células tronco',10]] as [$c,$n,$d]) $discount($c,$n,$d);
            } else {
                $add('MEDICACAO','Medicação durante as consultas',null,60,'contract_year','Somente dipirona, enrofloxacina, penicilina e meloxican, conforme contrato.',true);
                $add('INTERNAMENTO','Diárias de internamento',$slug==='prime'?30:15,60,'contract_year','Hospedagem, medicações e soro conforme coberturas e exclusões do contrato.',true);
                $add('EXAMES_CLINICA','Exames realizados na clínica',null,0,'contract_year','Hemograma, urina tipo 1 e fezes direto na lâmina. O texto não especifica carência própria; conferir antes de autorizar.',true);
                $surgery='Amputações, enucleações, esplenectomia, gastrotomia, imobilização simples, otohematoma e suturas em geral'.($slug==='essencial'?', tumores de pele':'').'. Não inclui anestesia nem cirurgias que exijam veterinários terceirizados.';
                $add('CIRURGIAS','Cirurgias relacionadas no contrato',null,90,'contract_year',$surgery,true);
                foreach([['DESC_UTI','UTI',$slug==='prime'?20:10],['DESC_BIOQ','Bioquímicos do rotor relacionados no contrato',$slug==='prime'?50:30],['DESC_CELULAS','Terapia com células tronco',$slug==='prime'?30:10],['DESC_ULTRA_ELETRO','Ultrassom e eletro',$slug==='prime'?20:10],['DESC_RAIOX','Raio-x',$slug==='prime'?50:30]] as [$c,$n,$d]) $discount($c,$n,$d,0,true);
            }
            foreach($rules as $rule) $version->benefitRules()->create($rule);
            return $version->fresh();
        });
    }

    public function installAll(): array
    {
        return array_map(fn($slug)=>$this->installOne($slug),array_keys(self::PLANS));
    }
}
