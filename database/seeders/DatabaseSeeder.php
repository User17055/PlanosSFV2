<?php

namespace Database\Seeders;

use App\Models\BenefitRule;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Contracts\ContractService;
use App\Services\Domain\ActivationService;
use App\Services\Domain\BenefitService;
use App\Services\Domain\CatalogService;
use App\Services\Domain\FinancialService;
use App\Services\Domain\ProposalService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! config('plans.demo')) {
            throw new \RuntimeException('A carga fictícia exige APP_DEMO=true e ambiente fora de produção.');
        }
        if (User::where('email', 'admin@saofrancisco.local')->exists()) {
            return;
        }
        $central = Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'city' => 'Presidente Prudente', 'state' => 'SP']);
        $goulart = Franchise::create(['name' => 'São Francisco · Manoel Goulart', 'code' => 'MANOEL_GOULART', 'city' => 'Presidente Prudente', 'state' => 'SP', 'address' => 'Av. Manoel Goulart, 1499']);
        $anaJacinta = Franchise::create(['name' => 'São Francisco · Ana Jacinta', 'code' => 'ANA_JACINTA', 'city' => 'Presidente Prudente', 'state' => 'SP', 'address' => 'Av. José Zerial, 749 - Conj. Hab. Ana Jacinta']);
        Franchise::create(['name' => 'São Francisco · Ademar de Barros', 'code' => 'ADEMAR_DE_BARROS', 'city' => 'Presidente Prudente', 'state' => 'SP', 'address' => 'Av. Ademar de Barros, 941 - Jardim Aviação']);
        Franchise::create(['name' => 'São Francisco · Damha', 'code' => 'DAMHA', 'city' => 'Presidente Prudente', 'state' => 'SP', 'address' => 'Av. Vereador Aurelino Coutinho, 2159 - Sala C']);
        $admin = $this->user('Administração central', 'admin', 'central_admin', null);
        $manager = $this->user('Mariana · Gestão', 'gestor', 'manager', $goulart->id);
        $sales = $this->user('Lucas · Comercial', 'vendas', 'sales', $goulart->id);
        $operator = $this->user('Beatriz · Recepção', 'recepcao', 'operator', $goulart->id);
        $finance = $this->user('Rafael · Financeiro', 'financeiro', 'finance', $goulart->id);
        foreach ([$manager, $sales, $operator, $finance] as $u) {
            $u->franchises()->attach($goulart->id, ['active' => true]);
        }
        $manager->franchises()->attach($anaJacinta->id, ['active' => true]);
        $template = "MODELO DEMONSTRATIVO — SEM VALIDADE COMERCIAL.\nCondições ilustrativas para homologação. Um contrato cobre exclusivamente o pet identificado. O produto, tutor e pet ficam congelados no aceite. Outro produto da mesma categoria exige encerramento e novo contrato, sem transferência de carências ou saldos.\nA vigência começa quando pagamento integral, contrato válido e demais requisitos forem confirmados, respeitada eventual data futura. Cada benefício tem carência própria, contada desse instante. A dívida vencida de qualquer Plano de Saúde do tutor bloqueia todos os benefícios em toda a rede. Pagamentos mensais não renovam saldos anuais. Documento, preços e coberturas devem ser aprovados antes da operação real.";
        $essential = $this->product($admin, 'Essencial', 'SF-ESS', 'health_plan', 14990, 'monthly', null, $template, [
            ['CONSULTA', 'Consulta veterinária', 30, 2, 'contract_year'], ['RETORNO', 'Retorno clínico', 0, null, 'monthly_cycle'],
            ['VACINA', 'Vacinação de rotina', 60, 3, 'contract_year'], ['HEMOGRAMA', 'Hemograma completo', 45, 1, 'contract_year']]);
        $complete = $this->product($admin, 'Completo', 'SF-COM', 'health_plan', 24990, 'monthly', null, $template, [
            ['CONSULTA', 'Consulta veterinária', 15, 4, 'contract_year'], ['RETORNO', 'Retorno clínico', 0, null, 'monthly_cycle'],
            ['EXAMES', 'Avaliação laboratorial', 30, 2, 'contract_year'], ['VACINA', 'Vacinação de rotina', 30, 4, 'contract_year']]);
        $wellbeing = $this->product($admin, 'Bem-estar', 'SF-BEM', 'package', 29900, 'one_time', 6, $template, [
            ['BANHO', 'Banho e cuidado', 0, 3, 'lifetime'], ['AVALIACAO', 'Avaliação preventiva', 10, 1, 'lifetime']]);
        $senior = $this->product($admin, 'Cuidado Sênior', 'SF-SEN', 'package', 45900, 'one_time', 6, $template, [
            ['CHECKUP', 'Check-up preventivo', 7, 1, 'lifetime'], ['ACOMPANHAMENTO', 'Acompanhamento clínico', 15, 2, 'lifetime']]);
        foreach ([$essential, $complete, $wellbeing, $senior] as $version) {
            CommissionRate::create(['product_id' => $version->product_id, 'rate_basis_points' => $version->product->category === 'package' ? 500 : 1000,
                'effective_from' => now()->subYear(), 'is_demo' => true, 'note' => 'Valor de exemplo: definir o percentual real.', 'created_by' => $admin->id]);
        }
        $names = [['Ana Paula Martins', 'Luna', 'dog', 'SRD', 'fêmea'], ['Bruno Almeida', 'Thor', 'dog', 'Golden retriever', 'macho'],
            ['Carla Oliveira', 'Mel', 'cat', 'SRD', 'fêmea'], ['Diego Santos', 'Nino', 'cat', 'Siamês', 'macho'],
            ['Elisa Costa', 'Amora', 'dog', 'Shih-tzu', 'fêmea'], ['Fábio Lima', 'Cookie', 'dog', 'Poodle', 'macho']];
        $customers = [];
        $pets = [];
        $clients = [];
        foreach ($names as $i => [$name,$petName,$species,$breed,$sex]) {
            $c = Customer::create(['name' => $name.' (demonstração)', 'document' => 'DEMO-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'email' => ['ana', 'bruno', 'carla', 'diego', 'elisa', 'fabio'][$i].'@exemplo.test', 'phone' => '(11) 90000-000'.($i + 1),
                'originating_franchise_id' => $i % 2 === 0 ? $goulart->id : $anaJacinta->id,
                'address' => ['city' => 'São Paulo', 'state' => 'SP', 'street' => 'Endereço fictício'],
                'contact_verified_at' => now(), 'financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
            $p = Pet::create(['customer_id' => $c->id, 'name' => $petName, 'species' => $species, 'breed' => $breed, 'sex' => $sex, 'birth_date' => now()->subYears($i + 2)->toDateString()]);
            $customers[] = $c;
            $pets[] = $p;
            $u = $this->user($name, $i === 0 ? 'cliente' : 'cliente'.($i + 1), 'customer', null, $c->id);
            $clients[] = $u;
        }
        Pet::create(['customer_id' => $customers[0]->id, 'name' => 'Bento', 'species' => 'cat', 'breed' => 'SRD', 'sex' => 'macho', 'birth_date' => now()->subYears(2)->toDateString()]);
        $subscriptions = [];
        $scenarios = [[$essential, 0, 70, true, true, 'site'], [$wellbeing, 0, 20, true, true, 'link'], [$complete, 1, 45, true, true, 'presential'], [$essential, 2, 5, true, true, 'link'], [$essential, 3, 2, false, true, 'site'], [$complete, 4, 1, true, false, 'link']];
        try {
            foreach ($scenarios as [$version,$index,$days,$document,$paid,$channel]) {
                Carbon::setTestNow(now()->subDays($days));
                $proposal = app(ProposalService::class)->create(['customer_id' => $customers[$index]->id, 'pet_id' => $pets[$index]->id,
                    'product_version_id' => $version->id, 'originating_franchise_id' => $index % 2 === 0 ? $goulart->id : $anaJacinta->id,
                    'channel' => $channel, 'signature_method' => 'digital'], $channel === 'site' ? $clients[$index] : $admin);
                $subscription = app(ProposalService::class)->accept($proposal, $clients[$index], ['accepted' => true, 'source' => 'demo_fixture', 'ip' => '127.0.0.1']);
                if ($document) {
                    app(ContractService::class)->generateDigital($subscription, ['affirmative' => true, 'signature_method' => 'digital', 'source' => 'demo_fixture', 'accepted_at' => now()->toIso8601String()]);
                }
                if ($paid) {
                    $invoice = $subscription->invoices()->firstOrFail();
                    app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'paid', 'paid_cents' => $invoice->amount_cents, 'paid_at' => now()->toIso8601String(),
                        'provider_updated_at' => now()->toIso8601String(), 'reference' => 'demo-seed-'.$invoice->id, 'source' => 'demo_seed']);
                }
                $subscriptions[] = $subscription->fresh();
                Carbon::setTestNow();
            }
        } finally {
            Carbon::setTestNow();
        }
        Customer::query()->update(['financial_checked_at' => now()]);
        Subscription::query()->update(['financial_checked_at' => now()]);
        foreach (Subscription::whereNotIn('status', Subscription::TERMINAL)->get() as $subscription) {
            app(ActivationService::class)->activate($subscription);
        }
        $debt = $subscriptions[2];
        Invoice::create(['subscription_id' => $debt->id, 'customer_id' => $debt->customer_id, 'originating_franchise_id' => $anaJacinta->id,
            'competence_start' => now()->subDays(15), 'competence_end' => now()->addDays(15), 'due_at' => now()->subDays(3), 'amount_cents' => $debt->price_cents,
            'status' => 'pending', 'reconciliation_status' => 'verified', 'provider_status' => 'demo_pending']);
        app(ActivationService::class)->activate($debt->fresh());
        $consult = $essential->benefitRules()->where('code', 'CONSULTA')->firstOrFail();
        $usage = app(BenefitService::class)->reserve($subscriptions[0]->fresh(), $consult, $operator, $goulart->id, 1, 'demo-consultation-1', 'demo-service-1');
        app(BenefitService::class)->consume($usage, $operator);
        app(ProposalService::class)->create(['customer_id' => $customers[5]->id, 'pet_id' => $pets[5]->id, 'product_version_id' => $senior->id,
            'originating_franchise_id' => $goulart->id, 'channel' => 'link', 'prefill_by' => 'customer'], $sales);
    }

    private function user(string $name, string $login, string $role, ?int $franchiseId, ?int $customerId = null): User
    {
        return User::create(['name' => $name, 'email' => $login.'@saofrancisco.local', 'password' => 'Demo@2026!', 'role' => $role, 'franchise_id' => $franchiseId, 'customer_id' => $customerId, 'active' => true, 'email_verified_at' => now()]);
    }

    private function product(User $admin, string $name, string $code, string $category, int $price, string $billing, ?int $duration, string $template, array $rules): ProductVersion
    {
        $p = Product::create(['name' => $name, 'code' => $code, 'category' => $category, 'description' => 'Condições ilustrativas para homologação.']);
        $v = ProductVersion::create(['product_id' => $p->id, 'version' => 1, 'name' => $name, 'description' => 'Produto demonstrativo · condições aguardam aprovação comercial.',
            'price_cents' => $price, 'billing_cycle' => $billing, 'duration_months' => $duration, 'renewal_policy' => 'none', 'species' => ['dog', 'cat'],
            'min_age_months' => 2, 'max_age_months' => 216, 'contract_template' => $template, 'is_demo' => true]);
        foreach ($rules as [$code,$name,$wait,$limit,$period]) {
            BenefitRule::create(['product_version_id' => $v->id, 'code' => $code, 'name' => $name, 'waiting_days' => $wait, 'quantity_limit' => $limit, 'period_type' => $period]);
        }

        return app(CatalogService::class)->publish($v, $admin);
    }
}
