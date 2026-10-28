<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, Pet, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
        $this->actingAs(User::where('role', 'central_admin')->firstOrFail());
    }

    public function test_tutor_and_first_pet_are_created_together_in_one_workspace(): void
    {
        $beforeCustomers = Customer::count();
        $beforePets = Pet::count();
        $franchise = Franchise::where('active', true)->firstOrFail();

        $response = $this->post(route('customers.store'), [
            'name' => 'Tutor da ficha única', 'document' => '529.982.247-25',
            'email' => 'ficha.unica@example.test', 'phone' => '11999998888',
            'address' => 'Rua da Ficha, 10', 'originating_franchise_id' => $franchise->id,
            'rg' => 'RG123456', 'city' => 'Presidente Prudente', 'state' => 'SP', 'zip' => '19000-000',
            'pet_name' => 'Pingo', 'pet_species' => 'dog', 'pet_birth_date' => now()->subYear()->toDateString(),
            'pet_breed' => 'SRD', 'pet_sex' => 'male',
            'pet_color' => 'Caramelo',
        ]);

        $customer = Customer::where('email', 'ficha.unica@example.test')->firstOrFail();
        $response->assertRedirect(route('customers.show', $customer).'#pets');
        $this->assertSame($beforeCustomers + 1, Customer::count());
        $this->assertSame($beforePets + 1, Pet::count());
        $this->assertSame('Pingo', $customer->pets()->sole()->name);
        $this->assertSame('Caramelo', $customer->pets()->sole()->color);
        $this->assertSame('RG123456', $customer->rg);
        $this->assertSame('19000-000', $customer->address['zip']);
    }

    public function test_missing_first_pet_does_not_leave_a_partial_tutor_record(): void
    {
        $before = Customer::count();
        $this->from(route('customers.create'))->post(route('customers.store'), [
            'name' => 'Cadastro incompleto', 'document' => '52998224725',
            'email' => 'incompleto@example.test', 'phone' => '11999997777',
            'originating_franchise_id' => Franchise::where('active', true)->value('id'),
        ])->assertRedirect(route('customers.create'))->assertSessionHasErrorsIn('customerCreate', ['pet_name', 'pet_birth_date']);

        $this->assertSame($before, Customer::count());
        $this->assertFalse(Customer::where('email', 'incompleto@example.test')->exists());
    }

    public function test_staff_manage_tutor_pets_and_plan_context_from_the_same_record(): void
    {
        $customer = Customer::with('pets')->whereHas('pets')->firstOrFail();
        $pet = $customer->pets->first();

        $this->get(route('customers.show', $customer))->assertOk()
            ->assertSee('data-customer-profile',false)->assertSee('data-profile-tab="pets"',false)->assertSee('data-profile-tab="contratos"',false)->assertSee('Dados do tutor')
            ->assertSee(route('proposals.create', ['customer_id' => $customer->id, 'pet_id' => $pet->id]));

        $this->get(route('customers.edit', $customer))
            ->assertRedirect(route('customers.show', ['customer' => $customer, 'edit' => 'tutor']).'#tutor');
        $this->get(route('pets.edit', $pet))
            ->assertRedirect(route('customers.show', ['customer' => $customer, 'edit' => 'pet-'.$pet->id]).'#pet-'.$pet->id);
    }

    public function test_profile_returns_to_the_correct_form_after_validation_and_keeps_entered_values(): void
    {
        $customer=Customer::whereHas('pets')->firstOrFail();
        $this->post(route('customers.pets.store',$customer),['_workspace_form'=>'pet-add','name'=>'Novo pet sem nascimento','species'=>'dog'])
            ->assertRedirect(route('customers.show',$customer).'#novo-pet')->assertSessionHasErrorsIn('petCreate','birth_date');
        $response=$this->withCookie(config('session.cookie'),session()->getId())->get(route('customers.show',$customer))->assertOk();
        preg_match('/data-profile-initial="([^"]+)"/',$response->getContent(),$panel);
        $this->assertSame('pets',$panel[1]??'missing');
        $response->assertSee('data-profile-errors="true"',false)->assertSee('Novo pet sem nascimento');
    }

    public function test_financial_panel_is_hidden_from_sales_and_only_shows_authorized_unit_invoices(): void
    {
        $sales=User::where('role','sales')->firstOrFail();
        $subscription=\App\Models\Subscription::where('originating_franchise_id',$sales->franchise_id)->firstOrFail();
        $customer=$subscription->customer;
        $other=Franchise::where('id','!=',$sales->franchise_id)->firstOrFail();
        \App\Models\Invoice::create(['subscription_id'=>$subscription->id,'customer_id'=>$customer->id,'originating_franchise_id'=>$other->id,
            'amount_cents'=>987654321,'paid_cents'=>0,'status'=>'pending','due_at'=>now()->addDay()]);
        $this->actingAs($sales)->get(route('customers.show',$customer))->assertOk()->assertDontSee('data-profile-tab="financeiro"',false)->assertDontSee('data-profile-panel="financeiro"',false)->assertDontSee('9.876.543,21');
        $manager=User::where('role','manager')->firstOrFail();
        $this->actingAs($manager)->get(route('customers.show',$customer))->assertOk()->assertSee('data-profile-panel="financeiro"',false)->assertDontSee('9.876.543,21');
    }

    public function test_final_documents_and_benefits_remain_available_with_contracts_and_history(): void
    {
        $subscription=\App\Models\Subscription::where('status','active')->whereHas('contractDocuments',fn($q)=>$q->where('status','valid')->where('kind','final_contract'))->firstOrFail();
        $document=$subscription->contractDocuments()->where('status','valid')->where('kind','final_contract')->firstOrFail();
        $this->get(route('customers.show',$subscription->customer_id))->assertOk()->assertSee(route('contracts.download',$document))->assertSee('Ver benefícios e disponibilidade')->assertSee('data-profile-initial="resumo"',false);
        $this->post(route('subscriptions.cancel',$subscription),['reason'=>'Cancelamento para conferir histórico','profile_cancel_subscription_id'=>$subscription->id])
            ->assertRedirect(route('customers.show',$subscription->customer_id).'#assinatura-'.$subscription->id);
        $this->get(route('customers.show',$subscription->customer_id))->assertOk()->assertSee('id="historico-contratos"',false)->assertSee('id="assinatura-'.$subscription->id.'"',false)->assertSee(route('contracts.download',$document));
    }

    public function test_invalid_cancellation_reopens_the_correct_dialog_without_cancelling_contract(): void
    {
        $subscription=\App\Models\Subscription::where('status','active')->firstOrFail();
        $this->from(route('customers.show',$subscription->customer_id))->post(route('subscriptions.cancel',$subscription),[
            'reason'=>'ab','profile_cancel_subscription_id'=>$subscription->id,
        ])->assertRedirect()->assertSessionHasErrors('reason');
        $this->assertSame('active',$subscription->fresh()->status);
        $this->withCookie(config('session.cookie'),session()->getId())->get(route('customers.show',$subscription->customer_id))->assertOk()
            ->assertSee('data-profile-initial="contratos"',false)->assertSee('data-profile-reopen',false)->assertSee('>ab</textarea>',false);
    }
}
