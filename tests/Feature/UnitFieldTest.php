<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Conta de uma unidade só não escolhe unidade: o sistema usa a dela. */
class UnitFieldTest extends TestCase
{
    use RefreshDatabase;

    private const FIELD = 'name="originating_franchise_id"';

    private Franchise $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Franchise::create(['name' => 'Unidade do gerente', 'code' => 'CENTRAL', 'active' => true]);
    }

    private function manager(array $units): User
    {
        $user = User::factory()->create(['role' => 'manager', 'active' => true, 'franchise_id' => $units[0]->id]);
        $user->franchises()->sync(collect($units)->mapWithKeys(fn ($unit) => [$unit->id => ['active' => true]])->all());
        return $user;
    }

    public function test_account_of_one_unit_does_not_choose_and_new_customer_goes_to_its_unit(): void
    {
        Franchise::create(['name' => 'Outra unidade', 'code' => 'OUTRA', 'active' => true]);
        $manager = $this->manager([$this->unit]);

        foreach (['proposals.create' => 'Unidade responsável', 'customers.create' => 'Unidade do cadastro'] as $route => $label) {
            $this->actingAs($manager)->get(route($route))->assertOk()->assertDontSee($label)->assertDontSee('Selecione a unidade')
                ->assertSee('type="hidden" '.self::FIELD.' value="'.$this->unit->id.'"', false)->assertDontSee('Outra unidade');
        }

        $this->actingAs($manager)->post(route('customers.store'), ['name' => 'Tutora Nova', 'document' => '52998224725', 'email' => 'tutora@example.test', 'phone' => '18999990000',
            'originating_franchise_id' => $this->unit->id, 'pet_name' => 'Lua', 'pet_species' => 'dog', 'pet_birth_date' => '2020-01-01', 'holder_sex' => 'female', 'pet_neutered' => '1'])->assertRedirect();
        $this->assertSame($this->unit->id, Customer::where('document', '52998224725')->sole()->originating_franchise_id);
    }

    public function test_account_with_several_units_keeps_choosing(): void
    {
        $other = Franchise::create(['name' => 'Outra unidade', 'code' => 'OUTRA', 'active' => true]);
        $manager = $this->manager([$this->unit, $other]);
        foreach (['proposals.create' => 'Unidade responsável', 'customers.create' => 'Unidade do cadastro'] as $route => $label) {
            $this->actingAs($manager)->get(route($route))->assertOk()->assertSee($label)->assertSee('<select '.self::FIELD, false)->assertSee('Outra unidade');
        }
    }

    public function test_central_admin_chooses_only_while_there_is_more_than_one_unit(): void
    {
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('customers.create'))->assertOk()->assertDontSee('Unidade do cadastro');
        Franchise::create(['name' => 'Outra unidade', 'code' => 'OUTRA', 'active' => true]);
        $this->actingAs($admin)->get(route('customers.create'))->assertOk()->assertSee('Unidade do cadastro');
    }

    public function test_hidden_field_cannot_be_used_to_register_in_another_unit(): void
    {
        $other = Franchise::create(['name' => 'Outra unidade', 'code' => 'OUTRA', 'active' => true]);
        $this->actingAs($this->manager([$this->unit]))->post(route('customers.store'), ['name' => 'Tutora Nova', 'document' => '52998224725', 'email' => 'tutora@example.test', 'phone' => '18999990000',
            'originating_franchise_id' => $other->id, 'pet_name' => 'Lua', 'pet_species' => 'dog', 'pet_birth_date' => '2020-01-01', 'holder_sex' => 'female', 'pet_neutered' => '1'])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['document' => '52998224725']);
    }
}
