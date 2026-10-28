<?php

namespace Tests\Feature;

use App\Models\{Pet,Proposal,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true,'plans.financial_max_age_minutes'=>1440]);
        Storage::fake('local');
        $this->seed();
    }

    private function portalAs(string $login)
    {
        return $this->actingAs(User::where('email',$login.'@saofrancisco.local')->firstOrFail())->get(route('portal'))->assertOk();
    }

    public function test_pending_proposal_is_listed_as_an_action_for_the_tutor(): void
    {
        $user=User::where('email','cliente6@saofrancisco.local')->firstOrFail();
        $proposal=Proposal::where('customer_id',$user->customer_id)->where('status','awaiting_acceptance')->firstOrFail();
        $this->portalAs('cliente6')
            ->assertSee('Precisa da sua atenção')
            ->assertSee($proposal->number)
            ->assertSee('aguarda sua confirmação')
            ->assertSee(route('proposals.show',$proposal),false);
    }

    public function test_expired_proposal_is_not_offered_as_an_action(): void
    {
        $user=User::where('email','cliente6@saofrancisco.local')->firstOrFail();
        Proposal::where('customer_id',$user->customer_id)->update(['expires_at'=>now()->subHour()]);
        $this->portalAs('cliente6')->assertDontSee('aguarda sua confirmação')->assertDontSee('Precisa da sua atenção');
    }

    public function test_overdue_tutor_sees_amount_once_instead_of_repeated_per_benefit(): void
    {
        $this->portalAs('cliente2')
            ->assertSee('em pagamentos vencidos')
            ->assertSee('Regularizar')
            ->assertSee('Os benefícios voltam a ser liberados após a regularização dos pagamentos vencidos.')
            ->assertDontSee('Cliente com mensalidade vencida');
    }

    public function test_pet_without_care_links_to_a_new_contract_for_that_pet(): void
    {
        $user=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $pet=Pet::where('customer_id',$user->customer_id)->where('name','Bento')->firstOrFail();
        $this->portalAs('cliente')
            ->assertSee('Contratar para Bento')
            ->assertSee(route('proposals.create',['pet_id'=>$pet->id]),false)
            ->assertSee('Histórico de uso (1)')
            ->assertSee('Pagamentos anteriores (2)');
        $this->get(route('proposals.create',['pet_id'=>$pet->id]))->assertOk();
    }

    public function test_breadcrumb_does_not_repeat_the_home_page(): void
    {
        $this->portalAs('cliente')
            ->assertSee('<a href="'.route('portal').'">Início</a><span aria-hidden="true">/</span><strong aria-current="location">Meu cuidado</strong>',false)
            ->assertSee('Portal do tutor')
            ->assertDontSee('Gestão da rede');
    }
}
