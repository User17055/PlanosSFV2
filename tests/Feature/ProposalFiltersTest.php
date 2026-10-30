<?php

namespace Tests\Feature;

use App\Models\{Pet,Proposal};
use App\Services\Domain\{FinancialService,ProposalService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/** A equipe filtra as propostas por etapa (assinar, pagar, ativar, ativo), origem, canal e busca. */
class ProposalFiltersTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    private array $proposals = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpScenario();

        $this->proposals['assinar'] = $this->proposal('Mimi');
        $this->proposals['assinar']->update(['acquisition_source' => 'google', 'campaign_name' => 'Outubro Prime']);

        $this->proposals['pagar'] = $this->proposal('Bia');
        app(ProposalService::class)->accept($this->proposals['pagar'], $this->client, ['accepted' => true]);

        $this->proposals['ativar'] = $this->proposal('Nina');
        $subscription = app(ProposalService::class)->accept($this->proposals['ativar'], $this->client, ['accepted' => true]);
        $invoice = $subscription->invoices()->firstOrFail();
        app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'paid', 'paid_cents' => $invoice->amount_cents,
            'paid_at' => now()->toIso8601String(), 'provider_updated_at' => now()->toIso8601String(), 'reference' => 'tx-'.$invoice->id, 'source' => 'test_verified']);

        $this->proposals['ativo'] = $this->activeSubscription()->proposal;

        $this->proposals['encerrada'] = $this->proposal('Zeca');
        $this->proposals['encerrada']->update(['status' => 'cancelled']);
    }

    private function proposal(string $petName): Proposal
    {
        $pet = Pet::create(['customer_id' => $this->customer->id, 'name' => $petName, 'species' => 'dog', 'birth_date' => now()->subYears(4)->toDateString()]);
        return app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $pet->id,
            'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->unit->id, 'channel' => 'site'], $this->client);
    }

    private function pets(string $query): array
    {
        $html = $this->actingAs($this->admin)->get('/propostas'.$query)->assertOk()->getContent();
        return array_values(array_filter(['Mimi', 'Bia', 'Nina', 'Luna', 'Zeca'], fn ($pet) => str_contains($html, '<strong>'.$pet.'</strong>')));
    }

    public function test_each_proposal_gets_its_stage(): void
    {
        foreach ($this->proposals as $stage => $proposal) {
            $this->assertSame($stage, Proposal::with('subscription.invoices')->find($proposal->id)->stage(), $stage);
        }
    }

    public function test_stage_filter_matches_the_stage_shown_on_each_proposal(): void
    {
        $pets = ['assinar' => 'Mimi', 'pagar' => 'Bia', 'ativar' => 'Nina', 'ativo' => 'Luna', 'encerrada' => 'Zeca'];
        foreach ($pets as $stage => $pet) {
            $this->assertSame([$pet], $this->pets('?etapa='.$stage), $stage);
        }
        $this->assertCount(5, $this->pets(''));
    }

    public function test_origin_channel_and_search_filters(): void
    {
        $this->assertSame(['Mimi'], $this->pets('?origem=google'));
        $this->assertSame(['Bia'], $this->pets('?q=Bia'));
        $this->assertCount(5, $this->pets('?channel=site'));
        $this->assertSame([], $this->pets('?channel=link'));
        $this->actingAs($this->admin)->get('/propostas?origem=inexistente')->assertSessionHasErrors('origem');
        $this->actingAs($this->admin)->get('/propostas?origem=google')->assertSee('Google')->assertSee('Outubro Prime');
    }
}
