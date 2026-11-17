<?php

namespace Tests\Feature;

use App\Models\{BenefitRule, Franchise};
use App\Services\Contracts\{HealthPlanInstaller, OriginalWordContract};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Respostas da Ju em 17/11/2026: desconto sem carência, exames da clínica sem carência, exceções do desconto em cirurgias. */
class HealthPlanWaitingRulesTest extends TestCase
{
    use RefreshDatabase;

    private const ISENCAO = 'Isenção de carência para consultas, exames realizados na clínica e descontos; carência de 30 dias para as vacinas, carência de 60 dias para medicação e internamento e carência de 90 dias para cirurgias.';
    private const CIRURGIA = '10% de desconto em cirurgias, exceto castração, anestesia e cirurgias que precisem de especialista ou veterinário terceirizado.';

    public function test_contracts_and_rules_follow_the_answers_of_17_11(): void
    {
        $versions=collect(app(HealthPlanInstaller::class)->installAll())->keyBy(fn($v)=>$v->product->code);
        foreach($versions as $code=>$version) {
            foreach($version->benefitRules()->where('coverage_type','discount')->get() as $rule) $this->assertSame(0,$rule->waiting_days,$code.' '.$rule->code);
        }
        foreach(['SF-SUPER-ECONOMICO','SF-ECONOMICO'] as $code) {
            $this->assertStringContainsString('exceto para consultas e descontos.',$versions[$code]->contract_template);
            $this->assertStringContainsString('exceto para consultas e descontos.',$this->wordText($versions[$code]));
        }
        // O Felino não tem desconto nenhum: o texto dele fica como estava.
        $this->assertStringContainsString('Carência de 30 dias para os serviços acima exceto para consulta.',$versions['SF-ECONOMICO-FELINO']->contract_template);
        $this->assertStringContainsString(self::CIRURGIA,$versions['SF-ECONOMICO']->contract_template);
        $this->assertStringContainsString(self::CIRURGIA,$this->wordText($versions['SF-ECONOMICO']));
        $this->assertStringContainsString('castração',$versions['SF-ECONOMICO']->benefitRules()->where('code','DESC_CIRURGIA')->sole()->description);
        foreach(['SF-ESS','SF-PRIME'] as $code) {
            $this->assertStringContainsString(self::ISENCAO,$versions[$code]->contract_template);
            $this->assertStringContainsString(self::ISENCAO,$this->wordText($versions[$code]));
            $exams=$versions[$code]->benefitRules()->where('code','EXAMES_CLINICA')->sole();
            $this->assertSame(0,$exams->waiting_days);
            $this->assertNull($exams->staffNote());
            $this->assertStringContainsString('castração',$versions[$code]->benefitRules()->where('code','CIRURGIAS')->sole()->description);
        }
        $this->assertSame('Consultas ilimitadas em horário comercial.',$versions['SF-PRIME']->benefitRules()->where('code','CONSULTA')->sole()->description);
    }

    public function test_staff_notes_stay_out_of_what_the_tutor_sees(): void
    {
        $this->assertSame(['Um hemograma a cada seis meses, totalizando dois no plano.','Conferir intervalo semestral antes de autorizar.'],
            BenefitRule::splitDescription('Um hemograma a cada seis meses, totalizando dois no plano. Conferir intervalo semestral antes de autorizar.'));
        $this->assertSame([null,null],BenefitRule::splitDescription(null));

        $super=app(HealthPlanInstaller::class)->installOne('super-economico');
        $hemograma=$super->benefitRules()->where('code','HEMOGRAMA')->sole();
        $this->assertSame('Conferir intervalo semestral antes de autorizar.',$hemograma->staffNote());
        $this->assertSame('Conferir histórico de doses.',$super->benefitRules()->where('code','VACINA')->sole()->staffNote());

        // Catálogo: carência em dias no lugar de "Aplicação conforme condições do contrato", sem lembrete da equipe.
        $html=view('components.benefit-summary',['rule'=>$hemograma])->render();
        $this->assertStringContainsString('30 dias de carência',$html);
        $this->assertStringNotContainsString('Aplicação conforme',$html);
        $this->assertStringNotContainsString('Conferir',$html);
        $this->assertStringContainsString('Sem carência',view('components.benefit-summary',['rule'=>$super->benefitRules()->where('code','DESC_VACINA')->sole()])->render());

        // Site: o catálogo que o site lê não leva os lembretes.
        $key=str_repeat('test-site-key-',3);
        config(['site_portal.enabled'=>true,'site_portal.key'=>$key]);
        Franchise::create(['code'=>'CENTRAL','name'=>'Canal central','active'=>true]);
        $super->update(['status'=>'published','published_at'=>now()]);
        $response=$this->getJson('/api/site/v1/catalog',['X-Site-Key'=>$key])->assertOk();
        $this->assertStringNotContainsString('Conferir',$response->getContent());
        $this->assertStringContainsString('Um hemograma a cada seis meses',$response->getContent());
    }

    private function wordText($version): string
    {
        $entry=app(OriginalWordContract::class)->entry(['version'=>$version->toArray()]);
        $zip=new \ZipArchive;
        $this->assertTrue($zip->open(resource_path('contracts/word/'.$entry['docx_sha256'].'.docx')) === true);
        $xml=$zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(preg_replace('/<\/w:p>/', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
