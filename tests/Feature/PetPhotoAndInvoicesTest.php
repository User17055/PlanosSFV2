<?php

namespace Tests\Feature;

use App\Models\{Pet, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PetPhotoAndInvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true]);
        Storage::fake('local');
        $this->seed();
    }

    private function customerWithPet(string $email='cliente@saofrancisco.local'): array
    {
        $user=User::where('email',$email)->firstOrFail();
        return [$user,Pet::where('customer_id',$user->customer_id)->firstOrFail()];
    }

    public function test_customer_uploads_a_photo_and_only_the_owner_can_see_it(): void
    {
        [$user,$pet]=$this->customerWithPet();
        $this->actingAs($user)->post(route('pets.photo.store',$pet),['photo'=>UploadedFile::fake()->image('luna.jpg',300,300)])->assertRedirect();
        $pet->refresh();
        $this->assertNotNull($pet->photo_path);
        Storage::disk('local')->assertExists($pet->photo_path);
        $this->get(route('pets.photo',$pet))->assertOk();
        $this->get(route('portal'))->assertOk()->assertSee(route('pets.photo',$pet),false)->assertSee('Nasc.');
        $this->get(route('portal.pet',$pet))->assertOk()->assertSee('pet-hero-art',false);

        $first=$pet->photo_path;
        $this->post(route('pets.photo.store',$pet),['photo'=>UploadedFile::fake()->image('nova.png',200,200)])->assertRedirect();
        Storage::disk('local')->assertMissing($first);

        $other=User::where('email','cliente2@saofrancisco.local')->firstOrFail();
        $this->actingAs($other)->get(route('pets.photo',$pet))->assertStatus(403);
        $this->actingAs($other)->post(route('pets.photo.store',$pet),['photo'=>UploadedFile::fake()->image('x.jpg')])->assertStatus(403);
    }

    public function test_pet_page_shows_the_contracted_plan_and_home_has_no_payments_section(): void
    {
        [$user,$pet]=$this->customerWithPet();
        $plan=$pet->subscriptions()->with('productVersion')->firstOrFail()->productVersion->name;
        $this->actingAs($user)->get(route('portal'))->assertOk()->assertSee(route('portal.pet',$pet),false)->assertDontSee('id="minhas-faturas"',false);
        $this->get(route('portal.pet',$pet))->assertOk()->assertSee($pet->name)->assertSee($plan)->assertSee('Baixar contrato');
        $this->actingAs(User::where('email','cliente2@saofrancisco.local')->firstOrFail())->get(route('portal.pet',$pet))->assertNotFound();
        $this->actingAs(User::where('role','finance')->firstOrFail())->get(route('portal.pet',$pet))->assertForbidden();
    }

    public function test_pet_with_overdue_invoice_is_flagged_on_card_and_page_but_not_for_a_paid_up_customer(): void
    {
        [$late,$latePet]=$this->customerWithPet('cliente2@saofrancisco.local');
        $this->actingAs($late)->get(route('portal'))->assertOk()->assertSee('Em atraso')->assertSee('vencidos · regularize em Faturas');
        $this->get(route('portal.pet',$latePet))->assertOk()->assertSee('Em atraso');
        [$ok]=$this->customerWithPet();
        $this->actingAs($ok)->get(route('portal'))->assertOk()->assertDontSee('Em atraso');
    }

    public function test_plan_page_shows_balance_usage_history_contract_and_all_plans_of_the_pet(): void
    {
        [$user,$pet]=$this->customerWithPet();
        $plan=$pet->subscriptions()->where('category','health_plan')->firstOrFail();
        $plan->update(['financial_verification_status'=>'verified','financial_checked_at'=>now()]);
        $other=$pet->subscriptions()->where('id','!=',$plan->id)->firstOrFail();
        $page=$this->actingAs($user)->get(route('portal.plan',[$pet,$plan]))->assertOk();
        $page->assertSee($plan->productVersion->name)->assertSee('O que está disponível')->assertSee('Histórico de uso')
            ->assertSee('Contrato')->assertSee($plan->number)->assertSee('Planos de '.$pet->name)
            ->assertSee(route('portal.plan',[$pet,$other]),false);
        $page->assertSee('disponíveis');
        $this->get(route('portal.pet',$pet))->assertOk()->assertSee(route('portal.plan',[$pet,$plan]),false);
        // outro tutor, ou plano de outro pet, não abre
        $this->actingAs(User::where('email','cliente2@saofrancisco.local')->firstOrFail())->get(route('portal.plan',[$pet,$plan]))->assertNotFound();
        [,$luna2]=$this->customerWithPet('cliente2@saofrancisco.local');
        $this->actingAs($user)->get(route('portal.plan',[$luna2,$plan]))->assertNotFound();
        $this->actingAs(User::where('role','finance')->firstOrFail())->get(route('portal.plan',[$pet,$plan]))->assertForbidden();
    }

    public function test_invoice_opens_the_vindi_page_only_for_its_owner_and_only_for_trusted_addresses(): void
    {
        config(['integrations.vindi.payment_hosts'=>['app.vindi.com.br']]);
        [$user,$pet]=$this->customerWithPet();
        $invoice=\App\Models\Invoice::where('customer_id',$user->customer_id)->where('status','paid')->firstOrFail();
        $invoice->update(['payment_url'=>'https://app.vindi.com.br/customer/bills/123']);
        $this->actingAs($user)->get(route('portal.invoices'))->assertOk()->assertSee('Ver fatura')->assertSee(route('portal.invoice.open',$invoice),false)->assertDontSee('app.vindi.com.br',false);
        $this->get(route('portal.invoice.open',$invoice))->assertRedirect('https://app.vindi.com.br/customer/bills/123');
        $invoice->update(['payment_url'=>'https://malicioso.example/fatura']);
        $this->get(route('portal.invoice.open',$invoice))->assertRedirect(route('portal.invoices'))->assertSessionHas('error');
        $invoice->update(['payment_url'=>null]);
        $this->get(route('portal.invoice.open',$invoice))->assertRedirect(route('portal.invoices'));
        $this->actingAs(User::where('email','cliente2@saofrancisco.local')->firstOrFail())->get(route('portal.invoice.open',$invoice))->assertNotFound();
        $this->actingAs(User::where('role','finance')->firstOrFail())->get(route('portal.invoice.open',$invoice))->assertForbidden();
    }

    public function test_photo_must_be_a_small_real_image(): void
    {
        [$user,$pet]=$this->customerWithPet();
        $this->actingAs($user);
        $this->post(route('pets.photo.store',$pet),['photo'=>UploadedFile::fake()->create('golpe.php',10,'application/x-php')])->assertSessionHasErrors('photo');
        $this->post(route('pets.photo.store',$pet),['photo'=>UploadedFile::fake()->create('grande.jpg',5000,'image/jpeg')])->assertSessionHasErrors('photo');
        $this->assertNull($pet->fresh()->photo_path);
    }

    public function test_invoices_page_is_for_customers_and_appears_in_the_sidebar(): void
    {
        $this->get(route('portal.invoices'))->assertRedirect(route('login'));
        [$user]=$this->customerWithPet();
        $this->actingAs($user)->get(route('portal.invoices'))->assertOk()->assertSee('Faturas')->assertSee('Em aberto');
        $this->get(route('portal'))->assertOk()->assertSee(route('portal.invoices'),false);
        $this->actingAs(User::where('role','finance')->firstOrFail())->get(route('portal.invoices'))->assertForbidden();
    }
}
