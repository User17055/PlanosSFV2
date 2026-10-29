<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,ProductVersion,Proposal,Subscription,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\TestCase;

class HttpAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true,'plans.financial_max_age_minutes'=>1440]);
        Storage::fake('local');
        $this->seed();
    }

    public function test_all_administration_pages_render_with_seeded_data(): void
    {
        $this->actingAs(User::where('role','central_admin')->firstOrFail());
        foreach(['dashboard','customers.index','customers.create','catalog.index','catalog.create','proposals.index','proposals.create','finance.index','integrations.index','management.index'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('customers.show',Customer::firstOrFail()))->assertOk()->assertSee('Benefício disponível.');
        $this->get(route('proposals.show',Proposal::firstOrFail()))->assertOk();
    }

    public function test_customer_portal_public_catalog_and_registration_render(): void
    {
        $this->get(route('sales'))->assertRedirect(config('site_portal.public_url').'/planos-saude/');
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
        $this->actingAs(User::where('role','customer')->firstOrFail())->get(route('portal'))->assertOk();
        $this->get(route('proposals.create'))->assertOk();
    }

    public function test_roles_and_franchise_scope_cannot_be_bypassed(): void
    {
        $sales=User::where('role','sales')->firstOrFail();
        $this->actingAs($sales)->get(route('finance.index'))->assertForbidden();
        $this->get(route('management.index'))->assertForbidden();
        $this->get(route('integrations.index'))->assertForbidden();
        $other=Subscription::where('originating_franchise_id','!=',$sales->franchise_id)->firstOrFail();
        $this->get(route('proposals.show',$other->proposal_id))->assertForbidden();
        $this->postJson(route('proposals.accept',$other->proposal_id),['accepted'=>1])->assertForbidden();
    }

    public function test_customer_cannot_download_other_tutors_contract_or_use_staff_routes(): void
    {
        $user=User::where('role','customer')->firstOrFail();
        $this->actingAs($user);
        $other=Subscription::where('customer_id','!=',$user->customer_id)->whereHas('documents')->firstOrFail();
        $this->get(route('contracts.download',$other->documents()->firstOrFail()))->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('customers.index'))->assertForbidden();
    }

    public function test_deactivation_revokes_an_existing_authenticated_session(): void
    {
        $user=User::where('role','sales')->firstOrFail();
        $this->actingAs($user);
        $user->update(['active'=>false]);
        $this->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_revoking_default_franchise_membership_denies_access(): void
    {
        $user=User::where('role','sales')->firstOrFail();
        DB::table('user_franchises')->where('user_id',$user->id)->update(['active'=>false]);
        $this->assertFalse($user->hasFranchise($user->franchise_id));
        $this->actingAs($user)->get(route('customers.index'))->assertOk();
        $customer=Customer::whereHas('proposals',fn($q)=>$q->where('originating_franchise_id',$user->franchise_id))->firstOrFail();
        $this->get(route('customers.show',$customer))->assertForbidden();
    }

    public function test_unverified_account_cannot_open_personal_records(): void
    {
        $user=User::where('role','customer')->firstOrFail();
        $user->forceFill(['email_verified_at'=>null])->save();
        $this->actingAs($user)->get(route('portal'))->assertOk()->assertSee('Confirme seu e-mail');
        $this->get(route('proposals.index'))->assertRedirect(route('portal'));
    }

    public function test_contract_payment_and_cancellation_workflow_through_http(): void
    {
        $admin=User::where('role','central_admin')->firstOrFail();
        $customerUser=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $this->actingAs($admin)->post(route('catalog.store'),[
            'name'=>'Produto de homologação','code'=>'HTTP-HOMOLOG','category'=>'health_plan','version_name'=>'Versão de homologação',
            'price'=>'99,90','billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>str_repeat('Condições exclusivamente ilustrativas para homologação do fluxo de contratação. ',4),
            'benefits'=>[['code'=>'CONSULTA-HTTP','name'=>'Consulta demonstrativa','waiting_days'=>0,'quantity_limit'=>1,'period_type'=>'contract_year']],
        ])->assertRedirect(route('catalog.index'));
        $version=ProductVersion::where('name','Versão de homologação')->firstOrFail();
        $this->post(route('catalog.publish',$version))->assertRedirect();
        $this->actingAs($customerUser)->post(route('customers.pets.store',$customerUser->customer_id),[
            'name'=>'Pet da homologação','species'=>'dog','birth_date'=>now()->subYear()->toDateString(),'sex'=>'unknown',
        ])->assertRedirect();
        $pet=$customerUser->customer->pets()->where('name','Pet da homologação')->firstOrFail();
        $this->post(route('proposals.store'),[
            'customer_id'=>$customerUser->customer_id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>Franchise::where('code','CENTRAL')->value('id'),'channel'=>'site','prefill_by'=>'customer','signature_method'=>'digital',
        ])->assertRedirect();
        $proposal=Proposal::where('pet_id',$pet->id)->firstOrFail();
        $fingerprint=app(\App\Services\Domain\ProposalService::class)->reviewFingerprint($proposal);
        $this->post(route('proposals.accept',$proposal),['accepted'=>1,'review_fingerprint'=>$fingerprint,'signature_data'=>$this->signatureData()])->assertRedirect(route('proposals.show',$proposal));
        $subscription=$proposal->subscription()->firstOrFail();
        $this->assertSame('valid',$subscription->document_status);
        $this->assertNotSame('active',$subscription->status);
        $invoice=$subscription->invoices()->firstOrFail();
        $this->actingAs($admin)->post(route('finance.confirm',$invoice),['reference'=>'HTTP-RECEBIMENTO-001','amount_cents'=>9990])->assertRedirect();
        $this->assertSame('active',$subscription->fresh()->status);
        $this->post(route('subscriptions.cancel',$subscription),['reason'=>'Encerramento demonstrativo solicitado pelo titular'])->assertRedirect();
        $this->assertSame('cancelled',$subscription->fresh()->status);
        $this->assertDatabaseHas('pet_product_slots',['pet_id'=>$pet->id,'product_category'=>'health_plan','state'=>'released']);
    }

    public function test_customer_corrections_preserve_accepted_contract_and_are_audited(): void
    {
        $user=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $subscription=Subscription::where('customer_id',$user->customer_id)->firstOrFail();
        $snapshot=$subscription->contract_snapshot;
        $this->actingAs($user)->get(route('customers.edit',$user->customer_id))->assertOk();
        $this->post(route('customers.update',$user->customer_id),['name'=>'Nome cadastral atualizado','phone'=>'11999990000','address'=>'Endereço demonstrativo corrigido','reason'=>'Correção cadastral solicitada pelo titular'])->assertRedirect(route('portal'));
        $this->assertEquals($snapshot,$subscription->fresh()->contract_snapshot);
        $this->assertDatabaseHas('audit_logs',['action'=>'customer.updated','entity_id'=>$user->customer_id,'actor_id'=>$user->id]);
        $this->get(route('pets.edit',$subscription->pet_id))->assertOk();
        $other=Customer::where('id','!=',$user->customer_id)->firstOrFail();
        $this->post(route('customers.update',$other),['name'=>'Tentativa indevida'])->assertForbidden();
    }

    public function test_customer_cancellation_request_is_durable_idempotent_and_preserves_active_contract(): void
    {
        $user=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $subscription=Subscription::where('customer_id',$user->customer_id)->where('status','active')->firstOrFail();
        $this->actingAs($user);
        for($i=0;$i<2;$i++) $this->post(route('subscriptions.request-cancellation',$subscription),['reason'=>'Solicito encerramento conforme as condições do contrato'])->assertRedirect();
        $this->assertSame('active',$subscription->fresh()->status);
        $this->assertDatabaseHas('cancellation_requests',['subscription_id'=>$subscription->id,'requested_by'=>$user->id,'status'=>'pending']);
        $this->assertDatabaseCount('cancellation_requests',1);
        $this->actingAs(User::where('role','central_admin')->firstOrFail())->get(route('customers.show',$user->customer_id))->assertOk()->assertSee('Solicitação de cancelamento');
    }

    public function test_login_page_greets_an_existing_customer_sent_from_the_signup(): void
    {
        $this->get(route('login',['cadastro'=>'existente','login'=>'529.982.247-25']))->assertOk()
            ->assertSee('Você já tem cadastro.')->assertSee('value="529.982.247-25"',false);
        $this->get(route('login'))->assertOk()->assertDontSee('Você já tem cadastro.');
    }

    public function test_customer_can_login_with_cpf(): void
    {
        $user=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $user->customer->update(['document'=>'52998224725']);
        $this->post(route('authenticate'),['email'=>'529.982.247-25','password'=>'Demo@2026!'])->assertRedirect(route('portal'));
        $this->post(route('logout'));
        $this->post(route('authenticate'),['email'=>'11111111111','password'=>'Demo@2026!'])->assertSessionHasErrors('password');
        $this->post(route('authenticate'),['email'=>'abc','password'=>'x'])->assertSessionHasErrors('email');
        $this->from(route('login'))->followingRedirects()->post(route('authenticate'),['email'=>'cliente@saofrancisco.local','password'=>'errada'])
            ->assertSee('E-mail, CPF ou senha incorretos.')->assertDontSee('Confira as informações para continuar.');
    }

    public function test_customer_login_ignores_a_staff_only_intended_url_and_goes_to_the_portal(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('authenticate'),['email'=>'cliente@saofrancisco.local','password'=>'Demo@2026!'])->assertRedirect(route('portal'));
    }

    public function test_staff_login_keeps_a_permitted_intended_url(): void
    {
        $this->get(route('finance.index'))->assertRedirect(route('login'));
        $this->post(route('authenticate'),['email'=>'financeiro@saofrancisco.local','password'=>'Demo@2026!'])->assertRedirect(route('finance.index'));
    }

    public function test_registration_requires_email_verification_outside_demo_mode(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        config(['plans.demo'=>false]);
        $this->post(route('register.store'),[
            'name'=>'Titular de homologação','document'=>'52998224725','email'=>'titular-verificacao@example.test','phone'=>'11999990000',
            'address'=>'Endereço fictício de homologação','password'=>'SenhaTeste@2026!','password_confirmation'=>'SenhaTeste@2026!',
            'pet_name'=>'Pet da conta nova','pet_species'=>'dog','pet_birth_date'=>now()->subYear()->toDateString(),'privacy_accepted'=>1,
        ])->assertRedirect(route('portal'));
        $user=User::where('email','titular-verificacao@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->customer->contact_verified_at);
        \Illuminate\Support\Facades\Notification::assertSentTo($user,\Illuminate\Auth\Notifications\VerifyEmail::class);
        $this->get(route('portal'))->assertSee('Confirme seu e-mail');
        $url=\Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify',now()->addMinutes(60),['user'=>$user->id,'hash'=>sha1($user->email)]);
        $this->get($url)->assertRedirect(route('portal'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->customer->fresh()->contact_verified_at);
    }
}
