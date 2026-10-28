<?php

namespace Tests\Feature;

use App\Services\Contracts\{ContractService, HealthPlanInstaller, SourceContractRenderer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthPlanImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_real_plans_keep_their_original_text_prices_and_individual_layouts(): void
    {
        $versions=app(HealthPlanInstaller::class)->installAll();
        $this->assertCount(4,$versions);
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
            $this->assertStringStartsWith('%PDF-',app(ContractService::class)->templatePdf($version));
        }
        $this->assertSame([5200,8600,28200,33000],array_map(fn($v)=>$v->price_cents,$versions));
        $this->assertStringContainsString('plano Master',$versions[3]->contract_template);
        $this->assertSame(10,$versions[2]->benefitRules()->where('code','CONSULTA')->sole()->quantity_limit);
        $this->assertNull($versions[3]->benefitRules()->where('code','CONSULTA')->sole()->quantity_limit);
        app(HealthPlanInstaller::class)->installAll();
        $this->assertDatabaseCount('product_versions',4);
        $admin=\App\Models\User::factory()->create(['role'=>'central_admin','active'=>true]);
        $this->actingAs($admin)->get(route('catalog.index'))->assertOk()->assertSee('Plano Super Econômico')->assertSee('Plano Prime');
        $this->get(route('catalog.template-pdf',$versions[0]))->assertOk()->assertHeader('Content-Type','application/pdf');
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
        }
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
