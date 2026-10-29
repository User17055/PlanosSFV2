<?php

namespace Tests\Feature;

use App\Services\Contracts\ContractTemplate;
use App\Services\Contracts\EconomicContractInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContractTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_contract_is_personalized_including_signer_without_replacing_values_recursively(): void
    {
        $template = file_get_contents(resource_path('contracts/plano-economico.txt'));
        $snapshot = ['customer' => ['name' => 'Maria {{pet_nome}}', 'document' => '52998224725', 'rg' => 'RG-TESTE',
            'email' => 'maria@example.test', 'phone' => '(18) 90000-0000',
            'address' => ['formatted' => 'Rua Teste, 10', 'city' => 'Presidente Prudente', 'state' => 'SP', 'zip' => '19000-000']],
            'pet' => ['name' => 'Luna', 'species' => 'cat', 'breed' => 'SRD', 'color' => 'Branca', 'sex' => 'female', 'birth_date' => '2020-01-02']];
        $result = app(ContractTemplate::class)->render($template, $snapshot, ['accepted_at' => '2026-10-22T02:00:00Z']);
        $this->assertSame(2, substr_count($result, 'Maria {{pet_nome}}'));
        $this->assertStringContainsString('CPF: 529.982.247-25', $result);
        $this->assertStringContainsString('CEP: 19000-000', $result);
        $this->assertStringContainsString('Cor: Branca', $result);
        $this->assertStringContainsString('Data de nascimento: 02/01/2020', $result);
        $this->assertStringContainsString('21 de outubro de 2026', $result);
        $this->assertStringContainsString('1.032,00', $result);
        $this->assertStringNotContainsString('IAN LIMA', $result);
        $this->assertStringNotContainsString('{{contratante_', $result);
    }

    public function test_unsigned_copy_keeps_signature_date_blank_and_missing_optional_data_explicit(): void
    {
        $result = app(ContractTemplate::class)->render('{{contratante_rg}} | {{pet_cor}} | {{data_assinatura_extenso}}', []);
        $this->assertSame('Não informado | Não informado | ____ de ______________ de ________', $result);
    }

    public function test_unknown_placeholders_are_rejected_instead_of_appearing_in_the_contract(): void
    {
        $this->expectException(ValidationException::class);
        app(ContractTemplate::class)->render('{{contratante_nmoe}}', []);
    }

    public function test_installation_is_idempotent_and_preserves_economic_prices_and_discount_rules_as_draft(): void
    {
        $installer = app(EconomicContractInstaller::class);
        $version = $installer->install();
        $this->assertSame($version->id, $installer->install()->id);
        $this->assertSame('draft', $version->status);
        $this->assertSame(8600, $version->price_cents);
        $this->assertSame(11, $version->benefitRules()->count());
        $this->assertSame(7, $version->benefitRules()->where('coverage_type', 'discount')->count());
        $this->assertStringContainsString('{{contratante_nome}}', $version->contract_template);
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_administrator_reads_the_actual_template_with_placeholders_and_no_sample_data(): void
    {
        $version = app(EconomicContractInstaller::class)->install();
        $admin = \App\Models\User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('catalog.template-preview', $version))
            ->assertOk()->assertSee('{{contratante_nome}}')->assertSee('{{pet_nome}}')
            ->assertDontSee('Tutor de Exemplo')->assertDontSee('Pet de Exemplo');
        $this->assertFalse($version->is_demo);
        $this->get(route('catalog.edit', $version))->assertOk()->assertSee('Campos preenchidos automaticamente');
    }
}
