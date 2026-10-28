<?php

namespace Tests\Feature;

use App\Models\{Customer, Pet, ProductVersion, Proposal, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification, Storage, URL};
use Tests\TestCase;

class OnlineRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        config(['plans.demo'=>true]);
        $this->seed();
    }

    private function data(): array
    {
        return ['name'=>'Tutor Online', 'document'=>'52998224725', 'email'=>'online@example.test',
            'phone'=>'18999990000', 'address'=>'Rua Exemplo, 10', 'city'=>'Presidente Prudente', 'state'=>'SP', 'zip'=>'19000-000',
            'password'=>'OnlineTeste@2026', 'password_confirmation'=>'OnlineTeste@2026', 'privacy_accepted'=>1,
            'pet_name'=>'Pet Online', 'pet_species'=>'dog', 'pet_birth_date'=>'2020-01-01', 'pet_color'=>'Caramelo',
            'product_version_id'=>ProductVersion::where('status','published')->firstOrFail()->id];
    }

    public function test_plan_choice_creates_tutor_pet_and_proposal_and_continues_to_contract_review(): void
    {
        $data = $this->data();
        $this->get(route('register',['product_version_id'=>$data['product_version_id']]))
            ->assertOk()->assertSee('Continuar para o contrato')->assertSee('name="product_version_id"', false);
        $before = Customer::count();
        $response = $this->post(route('register.store'), $data + ['price_cents'=>1,'channel'=>'presential','originating_franchise_id'=>999]);
        $customer = Customer::where('email',$data['email'])->sole();
        $proposal = Proposal::where('customer_id',$customer->id)->sole();
        $response->assertRedirect(route('proposals.show',$proposal));
        $this->assertSame($before+1,Customer::count());
        $this->assertSame('Pet Online',$customer->pets()->sole()->name);
        $this->assertSame('site',$proposal->channel);
        $this->assertSame('customer',$proposal->prefill_by);
        $this->assertSame($data['product_version_id'],$proposal->product_version_id);
        $this->assertSame($proposal->productVersion->price_cents,$proposal->price_cents);
        $this->assertNull($proposal->accepted_at);
        $this->assertNull($proposal->subscription);
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Pet Online');
    }

    public function test_ineligible_pet_does_not_leave_partial_customer_pet_or_access_records(): void
    {
        $data = $this->data();
        $before = [Customer::count(),Pet::count(),User::count(),Proposal::count()];
        $this->postJson(route('register.store'),array_replace($data,['pet_birth_date'=>now()->toDateString()]))->assertStatus(422);
        $this->assertSame($before,[Customer::count(),Pet::count(),User::count(),Proposal::count()]);
        $this->assertGuest();
    }

    public function test_unpublished_plan_is_rejected_before_creating_the_tutor(): void
    {
        $draft = app(\App\Services\Contracts\EconomicContractInstaller::class)->install();
        $this->postJson(route('register.store'),array_replace($this->data(),['product_version_id'=>$draft->id]))->assertStatus(422);
        $this->assertDatabaseMissing('customers',['email'=>'online@example.test']);
    }

    public function test_email_verification_returns_the_client_to_their_automatically_created_proposal(): void
    {
        config(['plans.demo'=>false,'plans.commercial_approved'=>true]);
        $this->post(route('register.store'),$this->data())->assertRedirect(route('portal'));
        $user = User::where('email','online@example.test')->sole();
        $proposal = Proposal::where('customer_id',$user->customer_id)->sole();
        $this->get(route('proposals.show',$proposal))->assertRedirect(route('portal'));
        $url = URL::temporarySignedRoute('verification.verify',now()->addMinutes(30),['user'=>$user->id,'hash'=>sha1($user->email)]);
        $this->get($url)->assertRedirect(route('proposals.show',$proposal));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($proposal->fresh()->accepted_at);
    }
}
