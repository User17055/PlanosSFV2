<?php

namespace Tests\Feature;

use App\Services\Contracts\{ContractService, HealthPlanInstaller, SourceContractRenderer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class HealthPlanImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_real_plans_keep_their_original_text_prices_and_individual_layouts(): void
    {
        $versions=app(HealthPlanInstaller::class)->installAll();
        $this->assertCount(5,$versions);
        foreach($versions as $version) {
            $this->assertFalse($version->is_demo);
            $this->assertSame(12,$version->duration_months);
            $this->assertSame(hash('sha256',$version->contract_template),$version->contract_layout['template_sha256']);
            $this->assertStringContainsString('{{contratante_nome}}',$version->contract_template);
            $this->assertStringContainsString('{{contratante_cep}}',$version->contract_template);
            $this->assertStringContainsString('{{pet_nascimento}}',$version->contract_template);
            $this->assertSame(2,substr_count($version->contract_template,'{{contratante_nome}}'));
            $this->assertStringNotContainsString('@hotmail.com',$version->contract_template);
            $this->assertStringNotContainsString('@gmail.com',$version->contract_template);
            $html=app(SourceContractRenderer::class)->html(['version'=>$version->toArray()],[],true,null,false);
            $this->assertStringContainsString('{{contratante_nome}}',$html);
            $this->assertStringContainsString('CLÁUSULA PRIMEIRA',$html);
            $this->assertStringNotContainsString('Texto integral contratado',$html);
            $this->assertStringNotContainsString('Condições congeladas e benefícios',$html);
            // Word → PDF: no Windows pelo Microsoft Word, fora dele pelo LibreOffice (soffice, instalado na VPS e no job MySQL do CI).
            // Sem conversor nenhum, a geração tem de falhar com erro, nunca devolver um PDF vazio.
            $hasConverter=PHP_OS_FAMILY==='Windows' || (new ExecutableFinder)->find(config('plans.libreoffice_bin','soffice'))!==null;
            if ($hasConverter) $this->assertStringStartsWith('%PDF-',app(ContractService::class)->templatePdf($version));
            else $this->assertThrows(fn()=>app(ContractService::class)->templatePdf($version),\RuntimeException::class);
        }
        $this->assertSame([5200,8600,28200,33000,2400],array_map(fn($v)=>$v->price_cents,$versions));
        $felino=$versions[4];
        $this->assertSame(['cat'],$felino->species);
        $this->assertSame(['CONSULTA','VACINA','HEMOGRAMA'],$felino->benefitRules()->orderBy('id')->pluck('code')->all());
        $this->assertStringContainsString('{{contratante_rg}}',$felino->contract_template);
        $this->assertStringContainsString('{{contratante_email}}',$felino->contract_template);
        $this->assertStringContainsString('plano Prime.',$versions[3]->contract_template);
        $this->assertSame(10,$versions[2]->benefitRules()->where('code','CONSULTA')->sole()->quantity_limit);
        $this->assertNull($versions[3]->benefitRules()->where('code','CONSULTA')->sole()->quantity_limit);
        app(HealthPlanInstaller::class)->installAll();
        $this->assertDatabaseCount('product_versions',5);
        $admin=\App\Models\User::factory()->create(['role'=>'central_admin','active'=>true]);
        $this->actingAs($admin)->get(route('catalog.index'))->assertOk()->assertSee('Plano Super Econômico')->assertSee('Plano Prime');
        if (PHP_OS_FAMILY==='Windows') $this->get(route('catalog.template-pdf',$versions[0]))->assertOk()->assertHeader('Content-Type','application/pdf');
    }

    public function test_plans_no_longer_offer_feed_or_franpet_product_discounts(): void
    {
        foreach(app(HealthPlanInstaller::class)->installAll() as $version) {
            $this->assertSame(0,$version->benefitRules()->whereIn('code',['DESC_RACAO','DESC_PRODUTOS'])->count());
            $this->assertDoesNotMatchRegularExpression('/desconto na compra de ração|desconto em produtos franpet/iu',$version->contract_template);
            $this->assertDoesNotMatchRegularExpression('/desconto na compra de ração|desconto em produtos franpet/iu',json_encode($version->contract_layout,JSON_UNESCAPED_UNICODE));
        }
        foreach(json_decode(file_get_contents(resource_path('contracts/word/index.json')),true) as $entry) {
            $zip=new \ZipArchive; $zip->open(resource_path('contracts/word/'.$entry['docx_sha256'].'.docx'));
            $text=strip_tags($zip->getFromName('word/document.xml')); $zip->close();
            $this->assertDoesNotMatchRegularExpression('/desconto na compra de ração|desconto em produtos franpet/iu',$text);
            // Os Word de origem são contratos já preenchidos: CPF e e-mail do cliente não podem ficar no modelo.
            $this->assertDoesNotMatchRegularExpression('/\d{3}\.\d{3}\.\d{3}-\d{2}|@(gmail|hotmail)\./i',$text);
        }
    }

    public function test_contracts_carry_the_corrections_confirmed_on_16_11(): void
    {
        $versions=collect(app(HealthPlanInstaller::class)->installAll())->keyBy(fn($v)=>$v->product->code);
        $prime=$versions['SF-PRIME']->contract_template;
        $this->assertStringNotContainsString('Master',$prime);
        $this->assertStringContainsString('- TUMORES DE PELE',$prime);
        $this->assertStringContainsString("No caso de morte comprovada do pet o contrato poderá ser rescindido, sem multa.\n\nCLÁUSULA OITAVA",$prime);
        $this->assertStringContainsString('(SEISCENTOS E VINTE E QUATRO REAIS)',$versions['SF-SUPER-ECONOMICO']->contract_template);
        $felino=$versions['SF-ECONOMICO-FELINO']->contract_template;
        $this->assertStringContainsString('(VINTE E QUATRO REAIS)',$felino);
        $this->assertStringContainsString('POR MÊS) – TODAS as doses.',$felino);
        $this->assertStringContainsString('realizados no Hospital, sendo eles: uréia, creatinina',$versions['SF-ECONOMICO']->contract_template);
        foreach(['SF-ESS','SF-PRIME'] as $code) $this->assertStringContainsString('tumores de pele',$versions[$code]->benefitRules()->where('code','CIRURGIAS')->sole()->description);
        foreach(['SF-SUPER-ECONOMICO','SF-ECONOMICO','SF-ESS','SF-PRIME'] as $code) $this->assertSame(0,$versions[$code]->benefitRules()->where('code','DESC_PLANTAO')->sole()->waiting_days);
        // O Word que vira o PDF tem de dizer o mesmo que o texto.
        foreach($versions as $version) foreach(['Master','SEISENTOS','VINTEE QUATRO'] as $wrong) $this->assertStringNotContainsString($wrong,$this->wordText($version),$version->name);
        $this->assertStringContainsString('- TUMORES DE PELE',$this->wordText($versions['SF-PRIME']));
        $this->assertStringContainsString('No caso de morte comprovada do pet o contrato poderá ser rescindido, sem multa.',$this->wordText($versions['SF-PRIME']));
        $this->assertStringContainsString('(VINTE E QUATRO REAIS)',$this->wordText($versions['SF-ECONOMICO-FELINO']));
        $this->assertStringContainsString('POR MÊS) – TODAS as doses.',$this->wordText($versions['SF-ECONOMICO-FELINO']));
        $this->assertStringContainsString('colesterol, albumina e lipase.',$this->wordText($versions['SF-ECONOMICO']));
    }

    public function test_published_version_is_reused_only_while_contract_and_rules_are_the_same(): void
    {
        $installer=app(HealthPlanInstaller::class);
        $version=$installer->installOne('essencial');
        $version->update(['status'=>'published','published_at'=>now()]);
        // Nada mudou: devolve a publicada, sem rascunho novo.
        $this->assertSame($version->id,$installer->installOne('essencial')->id);
        $this->assertDatabaseCount('product_versions',1);
        // Regra diferente da publicada (ex.: a carência do plantão de antes): vira rascunho novo, e a publicada fica intacta.
        $old=$installer->installOne('prime');
        \Illuminate\Support\Facades\DB::table('benefit_rules')->where('product_version_id',$old->id)->where('code','DESC_PLANTAO')->update(['waiting_days'=>30]);
        $old->update(['status'=>'published','published_at'=>now()]);
        $draft=$installer->installOne('prime');
        $this->assertNotSame($old->id,$draft->id);
        $this->assertNull($draft->published_at);
        $this->assertSame(0,$draft->benefitRules()->where('code','DESC_PLANTAO')->sole()->waiting_days);
        $this->assertSame(30,$old->benefitRules()->where('code','DESC_PLANTAO')->sole()->waiting_days);
    }

    private function wordText($version): string
    {
        $entry=app(\App\Services\Contracts\OriginalWordContract::class)->entry(['version'=>$version->toArray()]);
        $zip=new \ZipArchive; $zip->open(resource_path('contracts/word/'.$entry['docx_sha256'].'.docx'));
        preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/',$zip->getFromName('word/document.xml'),$m); $zip->close();
        return html_entity_decode(implode('',$m[1]),ENT_QUOTES|ENT_XML1,'UTF-8');
    }

    public function test_personalized_source_contract_escapes_input_and_keeps_the_signing_record_separate(): void
    {
        $version=app(HealthPlanInstaller::class)->installOne('prime');
        $html=app(SourceContractRenderer::class)->html(['version'=>$version->toArray(),'customer'=>['name'=>'<script>Nome</script>','document'=>'123'],'pet'=>['name'=>'Pet Real']],['accepted_at'=>'2026-10-22T15:00:00-03:00','signature_method'=>'digital'],false);
        $this->assertStringContainsString('&lt;script&gt;Nome&lt;/script&gt;',$html);
        $this->assertStringContainsString('Pet Real',$html);
        $this->assertStringContainsString('Registro de assinatura',$html);
        $this->assertStringNotContainsString('{{contratante_nome}}',$html);
        $this->assertStringNotContainsString('<script>',$html);
    }
}
