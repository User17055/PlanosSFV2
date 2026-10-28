<?php

namespace Tests\Feature;

use App\Models\{Customer, Pet, User};
use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    public function test_pet_context_reuses_the_existing_tutor_and_pet_without_new_records(): void
    {
        $admin = User::where('role', 'central_admin')->firstOrFail();
        $pet = Pet::with('customer')->firstOrFail();
        $customersBefore = Customer::count();
        $petsBefore = Pet::count();

        $this->actingAs($admin)->get(route('proposals.create', ['pet_id' => $pet->id]))
            ->assertOk()
            ->assertViewHas('selectedCustomer', fn ($customer) => $customer->is($pet->customer))
            ->assertViewHas('selectedPet', fn ($selectedPet) => $selectedPet->is($pet))
            ->assertSee('value="'.$pet->id.'" data-customer="'.$pet->customer_id.'" selected', false)
            ->assertSee(route('customers.show', $pet->customer).'#pets', false);

        $this->assertSame($customersBefore, Customer::count());
        $this->assertSame($petsBefore, Pet::count());
    }

    public function test_context_cannot_select_a_pet_from_a_different_tutor(): void
    {
        $pet = Pet::firstOrFail();
        $other = Customer::whereKeyNot($pet->customer_id)->firstOrFail();
        $this->actingAs(User::where('role', 'central_admin')->firstOrFail())
            ->get(route('proposals.create', ['customer_id' => $other->id, 'pet_id' => $pet->id]))
            ->assertUnprocessable();
    }

    public function test_context_does_not_grant_sales_access_to_another_franchise(): void
    {
        $sales = User::where('role', 'sales')->firstOrFail();
        $authorized = app(AccessService::class)->customerQuery($sales)->select('customers.id');
        $otherPet = Pet::whereNotIn('customer_id', $authorized)->firstOrFail();
        $this->actingAs($sales)
            ->get(route('proposals.create', ['customer_id' => $otherPet->customer_id, 'pet_id' => $otherPet->id]))
            ->assertForbidden();
        $this->get(route('proposals.create', ['pet_id' => $otherPet->id]))->assertForbidden();
    }
}
