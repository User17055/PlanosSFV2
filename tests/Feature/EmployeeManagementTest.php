<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Customer,Franchise,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Franchise $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin=User::factory()->create(['role'=>'central_admin','active'=>true]);
        $this->unit=Franchise::create(['name'=>'Unidade A','code'=>'A','active'=>true]);
    }

    private function data(array $overrides=[]): array
    {
        return $overrides+['name'=>'Funcionária de vendas','email'=>'equipe@example.test','password'=>'SenhaIndividual@2026','password_confirmation'=>'SenhaIndividual@2026','employee_code'=>'F-001','phone'=>'18999990000','job_title'=>'Consultora de planos','hired_at'=>'2026-10-01','monthly_sales_target'=>'2500.50','active'=>1,'role'=>'sales','franchise_ids'=>[$this->unit->id]];
    }

    public function test_admin_registers_individual_employee_and_can_edit_without_resetting_password(): void
    {
        $this->actingAs($this->admin)->get(route('employees.create'))->assertOk();
        $this->post(route('employees.store'),$this->data())->assertRedirect(route('employees.index'));
        $employee=User::where('employee_code','F-001')->sole();
        $this->assertTrue(Hash::check('SenhaIndividual@2026',$employee->password));
        $this->assertSame(250050,$employee->monthly_sales_target_cents);
        $this->assertTrue($employee->hasFranchise($this->unit->id));
        $this->get(route('employees.index'))->assertOk()->assertSee('Funcionária de vendas')->assertSee('F-001');
        $this->get(route('employees.edit',$employee))->assertOk();
        $this->post(route('employees.update',$employee),$this->data(['name'=>'Nome atualizado','password'=>null,'password_confirmation'=>null,'active'=>0]))->assertRedirect(route('employees.index'));
        $this->assertFalse($employee->fresh()->active);
        $this->assertTrue(Hash::check('SenhaIndividual@2026',$employee->fresh()->password));
        $this->assertStringNotContainsString('SenhaIndividual',AuditLog::where('action','like','employee.%')->get()->toJson());
    }

    public function test_managers_can_only_list_their_units_and_cannot_create_or_change_employee_privileges(): void
    {
        $manager=User::factory()->create(['role'=>'manager','active'=>true]); $manager->franchises()->attach($this->unit->id,['active'=>true]);
        $local=User::factory()->create(['name'=>'Equipe visível','role'=>'sales','active'=>true]); $local->franchises()->attach($this->unit->id,['active'=>true]);
        $other=User::factory()->create(['name'=>'Equipe de outra unidade','role'=>'sales','active'=>true]);
        $this->actingAs($manager)->get(route('employees.index'))->assertOk()->assertSee('Equipe visível')->assertDontSee('Equipe de outra unidade');
        $this->get(route('employees.edit',$other))->assertForbidden();
        $this->post(route('employees.store'),$this->data())->assertForbidden();
        $this->actingAs($local)->get(route('employees.index'))->assertForbidden();
    }

    public function test_duplicate_emails_matriculas_inactive_units_and_customer_profiles_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('employees.store'),$this->data())->assertRedirect();
        $this->postJson(route('employees.store'),$this->data())->assertUnprocessable()->assertJsonValidationErrors(['email','employee_code']);
        $inactive=Franchise::create(['name'=>'Desativada','code'=>'OFF','active'=>false]);
        $this->postJson(route('employees.store'),$this->data(['email'=>'outro@example.test','employee_code'=>'F-002','franchise_ids'=>[$inactive->id]]))->assertUnprocessable();
        $customer=User::factory()->create(['role'=>'customer','active'=>true]);
        $this->get(route('employees.edit',$customer))->assertNotFound();
        $this->postJson(route('employees.update',$customer),$this->data())->assertNotFound();
    }
}
