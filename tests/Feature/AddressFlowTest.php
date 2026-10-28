<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise};
use App\Services\Contracts\ContractTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Route};
use Tests\TestCase;

class AddressFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true]);
        Franchise::create(['code'=>'CENTRAL','name'=>'Central','active'=>true]);
    }

    private function data(): array
    {
        return ['name'=>'Tutor do endereço','document'=>'52998224725','email'=>'endereco@example.test','phone'=>'18999990000',
            'password'=>'SenhaSegura@2026','password_confirmation'=>'SenhaSegura@2026','privacy_accepted'=>1,
            'pet_name'=>'Lua','pet_species'=>'dog','pet_birth_date'=>'2020-01-01',
            '_structured_address'=>1,'zip'=>'19045-060','street'=>'Rua do Teste','number'=>'123','complement'=>'Casa 2',
            'neighborhood'=>'Jardim Cambuci','city'=>'Presidente Prudente','state'=>'sp'];
    }

    public function test_cep_precedes_street_and_number_in_online_registration(): void
    {
        $html=$this->get(route('register'))->assertOk()->getContent();
        $this->assertLessThan(strpos($html,'name="street"'),strpos($html,'name="zip"'));
        $this->assertLessThan(strpos($html,'name="number"'),strpos($html,'name="street"'));
        $this->assertStringNotContainsString('name="address"',$html);
    }

    public function test_number_is_required_and_missing_number_does_not_create_partial_registration(): void
    {
        $data=$this->data();unset($data['number']);
        $this->postJson(route('register.store'),$data)->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->assertDatabaseCount('customers',0);
        $this->assertDatabaseCount('users',0);
    }

    public function test_structured_address_is_saved_and_number_and_complement_reach_contract(): void
    {
        $this->post(route('register.store'),$this->data())->assertRedirect();
        $customer=Customer::sole();
        $this->assertSame('Rua do Teste, 123, Casa 2',$customer->address['formatted']);
        $this->assertSame('123',$customer->address['number']);
        $this->assertSame('SP',$customer->address['state']);
        $text=app(ContractTemplate::class)->render('{{contratante_endereco}} | {{contratante_cidade}} | {{contratante_cep}}',['customer'=>$customer->toArray()]);
        $this->assertSame('Rua do Teste, 123, Casa 2 | Presidente Prudente | 19045-060',$text);
    }

    public function test_postal_endpoint_supplies_street_but_never_invents_a_house_number(): void
    {
        Http::fake(['viacep.com.br/*'=>Http::response(['cep'=>'19045-060','logradouro'=>'Rua de Consulta','bairro'=>'Jardim Cambuci','localidade'=>'Presidente Prudente','uf'=>'SP'])]);
        $response=$this->getJson(route('addresses.postal',['zip'=>'19045060']))->assertOk()->assertJsonPath('street','Rua de Consulta');
        $this->assertArrayNotHasKey('number',$response->json());
    }

    public function test_server_error_renders_recovery_screen_without_laravel_trace(): void
    {
        Route::get('/_test/recovery-error',fn()=>throw new \RuntimeException('INTERNAL_DETAILS_NOT_FOR_CUSTOMER'));
        $this->get('/_test/recovery-error')->assertStatus(500)->assertSee('Retomar minhas propostas')->assertDontSee('INTERNAL_DETAILS_NOT_FOR_CUSTOMER');
    }

    public function test_registration_keeps_referral_for_later_hiring_without_rewriting_accepted_history(): void
    {
        $this->post(route('register.store'),$this->data()+['acquisition_source'=>'instagram','referrer_name'=>'Maria Indicadora'])->assertRedirect();
        $customer=Customer::sole();
        $this->assertSame('instagram',$customer->acquisition_profile['acquisition_source']);$this->assertSame('Maria Indicadora',$customer->acquisition_profile['referrer_name']);
        $product=\App\Models\Product::create(['name'=>'Plano teste','code'=>'REFERRAL','category'=>'health_plan','active'=>true]);
        $version=\App\Models\ProductVersion::create(['product_id'=>$product->id,'version'=>1,'name'=>'Versão','status'=>'published','published_at'=>now(),'price_cents'=>8600,'billing_cycle'=>'monthly','duration_months'=>12,'species'=>['dog'],'contract_template'=>'Condições contratuais']);
        $this->post(route('proposals.store'),['customer_id'=>$customer->id,'pet_id'=>$customer->pets()->sole()->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>Franchise::sole()->id,'channel'=>'site','prefill_by'=>'customer','signature_method'=>'digital'])->assertRedirect();
        $proposal=\App\Models\Proposal::sole();
        $this->assertSame('instagram',$proposal->acquisition_source);$this->assertSame('Maria Indicadora',$proposal->referrer_name);
        $subscription=app(\App\Services\Domain\ProposalService::class)->accept($proposal,auth()->user(),['accepted'=>true,'affirmative'=>true]);
        $this->post(route('customers.update',$customer),['name'=>$customer->name,'phone'=>$customer->phone,'address'=>'Rua atualizada, 321','reason'=>'Correção da origem informada',
            'acquisition_source'=>'google','referrer_name'=>'Outra pessoa'])->assertRedirect();
        $this->assertSame('google',$customer->fresh()->acquisition_profile['acquisition_source']);
        $this->assertSame('Maria Indicadora',$proposal->fresh()->referrer_name);$this->assertSame('instagram',$subscription->fresh()->acquisition_source);
    }
}
