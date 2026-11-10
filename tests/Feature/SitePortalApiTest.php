<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,Product,ProductVersion,Proposal,User};
use App\Notifications\PortalVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Notification,Storage,URL};
use Tests\TestCase;

class SitePortalApiTest extends TestCase
{
    use RefreshDatabase;
    private string $key;
    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key=str_repeat('test-site-key-',3);
        config(['site_portal.enabled'=>true,'site_portal.key'=>$this->key,'plans.demo'=>true,'site_portal.public_url'=>'https://saofrancisco.vet.br']);
        Notification::fake(); Storage::fake('local'); Http::preventStrayRequests();
        Franchise::create(['code'=>'CENTRAL','name'=>'Canal central','active'=>true]);
        $product=Product::create(['code'=>'SITE-TEST','name'=>'Cuidado','category'=>'health_plan','active'=>true]);
        $this->version=$product->versions()->create(['version'=>1,'name'=>'Plano cuidado','price_cents'=>9900,'billing_cycle'=>'monthly',
            'species'=>['dog','cat'],'is_demo'=>true,'contract_template'=>'Contrato de {{contratante_nome}} para {{pet_nome}}.']);
        $this->version->benefitRules()->create(['code'=>'CONSULTA','name'=>'Consulta','waiting_days'=>30,'quantity_limit'=>2,'period_type'=>'contract_year','coverage_type'=>'included']);
        $this->version->update(['status'=>'published','published_at'=>now()]);
    }

    private function headers(?string $token=null): array { return ['X-Site-Key'=>$this->key]+($token?['Authorization'=>'Bearer '.$token]:[]); }
    private function data(): array
    {
        return ['name'=>'Tutor do site','document'=>'52998224725','email'=>'site@example.test','phone'=>'18999990000',
            'street'=>'Rua de teste','number'=>'10','neighborhood'=>'Centro','city'=>'Presidente Prudente','state'=>'SP','zip'=>'19000000',
            'password'=>'SitePassword@2026','password_confirmation'=>'SitePassword@2026','privacy_accepted'=>1,
            'pet_name'=>'Lua','pet_species'=>'dog','pet_birth_date'=>'2020-01-01','product_version_id'=>$this->version->id];
    }
    private function registered(): array
    {
        return $this->postJson('/api/site/v1/register',$this->data(),$this->headers())->assertCreated()->json();
    }

    public function test_gateway_requires_its_private_key_and_can_be_disabled(): void
    {
        $this->getJson('/api/site/v1/catalog')->assertUnauthorized();
        config(['site_portal.enabled'=>false]);
        $this->getJson('/api/site/v1/catalog',$this->headers())->assertStatus(503);
    }

    public function test_catalog_is_versioned_and_does_not_expose_private_contract_or_customer_data(): void
    {
        $response=$this->getJson('/api/site/v1/catalog',$this->headers())->assertOk()->assertJsonPath('offers.0.price_cents',9900);
        $this->assertArrayNotHasKey('contract_template',$response->json('offers.0'));
        $this->assertArrayNotHasKey('customer',$response->json());
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }

    public function test_catalog_shows_real_draft_plans_as_informational_only_when_enabled(): void
    {
        $product=Product::create(['code'=>'SITE-REAL','name'=>'Real','category'=>'health_plan','active'=>true]);
        $product->versions()->create(['version'=>1,'name'=>'Plano real','price_cents'=>8600,'billing_cycle'=>'monthly','species'=>['dog'],
            'is_demo'=>false,'contract_layout'=>'x','contract_template'=>'Contrato.','status'=>'draft']);
        $this->getJson('/api/site/v1/catalog',$this->headers())->assertOk()->assertJsonCount(1,'offers');
        config(['site_portal.show_drafts'=>true]);
        $offers=$this->getJson('/api/site/v1/catalog',$this->headers())->assertOk()->assertJsonCount(2,'offers')->json('offers');
        $this->assertFalse(collect($offers)->firstWhere('name','Plano cuidado')['informational']);
        $this->assertTrue(collect($offers)->firstWhere('name','Plano real')['informational']);
    }

    public function test_account_check_tells_when_cpf_or_email_already_have_access(): void
    {
        $this->getJson('/api/site/v1/account-check?document=&email=',$this->headers())->assertStatus(405);
        $this->postJson('/api/site/v1/account-check',['document'=>'529.982.247-25','email'=>'site@example.test'],$this->headers())->assertOk()->assertJsonPath('code','AVAILABLE');
        $this->registered();
        $this->postJson('/api/site/v1/account-check',['document'=>'529.982.247-25','email'=>'outro@example.test'],$this->headers())->assertOk()->assertJsonPath('code','ACCOUNT_EXISTS')->assertJsonPath('by','document');
        $this->postJson('/api/site/v1/account-check',['document'=>'11144477735','email'=>'site@example.test'],$this->headers())->assertOk()->assertJsonPath('code','ACCOUNT_EXISTS')->assertJsonPath('by','email');
        $this->postJson('/api/site/v1/account-check',['document'=>'11144477735','email'=>'novo@example.test'],$this->headers())->assertOk()->assertJsonPath('code','AVAILABLE');
        // O que o cadastro final recusaria aparece já no "Prosseguir" dos dados.
        $this->postJson('/api/site/v1/account-check',['document'=>'111.444.777-30','email'=>'novo@example.test'],$this->headers())->assertOk()->assertJsonPath('code','INVALID_CPF')->assertJsonPath('field','document');
        \App\Models\User::factory()->create(['role'=>'manager','email'=>'equipe@example.test']);
        $this->postJson('/api/site/v1/account-check',['document'=>'11144477735','email'=>'equipe@example.test'],$this->headers())->assertOk()->assertJsonPath('code','EMAIL_IN_USE')->assertJsonPath('field','email');
    }

    public function test_registration_accepts_several_pets_and_opens_one_proposal_per_pet(): void
    {
        $data=$this->data(); foreach(['pet_name','pet_species','pet_birth_date'] as $k) unset($data[$k]);
        $data['holder_sex']='female';
        $data['pets']=[['name'=>'Lua','species'=>'dog','birth_date'=>'2020-01-01','breed'=>'SRD','color'=>'Caramelo','sex'=>'female','neutered'=>'1'],['name'=>'Mel','species'=>'cat','birth_date'=>'2021-05-05','breed'=>'Siamês','color'=>'Creme','sex'=>'male','neutered'=>'0']];
        // Raça, cor e sexo vão para o contrato; sexo do titular e castração, para o SimplesVet: sem eles o cadastro é recusado.
        $missing=$data; unset($missing['pets'][1]['sex'],$missing['pets'][0]['neutered'],$missing['holder_sex']); $missing['pets'][0]['breed']='';
        $this->postJson('/api/site/v1/register',$missing,$this->headers())->assertUnprocessable()->assertJsonValidationErrors(['pets.0.breed','pets.1.sex','pets.0.neutered','holder_sex']);
        $result=$this->postJson('/api/site/v1/register',$data,$this->headers())->assertCreated()->assertJsonCount(2,'proposal_ids')->json();
        $this->assertDatabaseCount('pets',2); $this->assertDatabaseCount('proposals',2);
        $this->assertSame([true,false],\App\Models\Pet::orderBy('id')->pluck('neutered')->all());
        $this->assertSame('female',\App\Models\Customer::where('document',$data['document'])->value('sex'));
        $this->assertSame($result['proposal_ids'][0],$result['proposal_id']);
        $data['email']='outro@example.test'; $data['document']='11144477735'; $data['pets']=[];
        $this->postJson('/api/site/v1/register',$data,$this->headers())->assertUnprocessable();
    }

    public function test_new_account_goes_from_the_site_to_the_customer_area_without_logging_in_again(): void
    {
        $result=$this->registered();
        $url=$this->postJson('/api/site/v1/management-access',[],$this->headers($result['token']))->assertOk()->json('url');
        $this->assertStringContainsString('/acesso/site/',$url);
        $this->get($url)->assertRedirect(route('portal'));
        $this->assertAuthenticated();
        $this->assertSame('customer',auth()->user()->role);
        auth()->logout();
        // O link vale uma vez só e exige a sessão do site.
        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->postJson('/api/site/v1/management-access',[],$this->headers())->assertUnauthorized();
    }

    public function test_registration_creates_same_records_used_by_management_and_only_hashes_the_access_token(): void
    {
        $result=$this->registered();
        $this->assertDatabaseCount('customers',1); $this->assertDatabaseCount('pets',1); $this->assertDatabaseCount('proposals',1);
        $this->assertDatabaseHas('portal_tokens',['token_hash'=>hash('sha256',$result['token'])]);
        $this->assertDatabaseMissing('portal_tokens',['token_hash'=>$result['token']]);
        $this->getJson('/api/site/v1/me',$this->headers($result['token']))->assertOk()->assertJsonPath('pets.0.name','Lua');
        $this->assertSame('site',Proposal::first()->channel);
        $this->assertNull(Proposal::first()->accepted_at);
    }

    public function test_invalid_pet_rolls_back_all_registration_records(): void
    {
        $this->postJson('/api/site/v1/register',array_replace($this->data(),['pet_birth_date'=>'2035-01-01']),$this->headers())->assertUnprocessable();
        $this->assertDatabaseCount('customers',0); $this->assertDatabaseCount('users',0); $this->assertDatabaseCount('portal_tokens',0);
    }

    public function test_existing_cpf_cannot_be_used_to_claim_a_customer_record(): void
    {
        Customer::create(['name'=>'Existing','document'=>$this->data()['document']]);
        $this->postJson('/api/site/v1/register',$this->data(),$this->headers())->assertConflict()->assertJsonPath('code','CUSTOMER_WITHOUT_ACCESS');
        $this->assertDatabaseCount('users',0);
    }

    public function test_existing_account_is_sent_to_login_by_cpf_or_email(): void
    {
        $this->registered();
        $this->postJson('/api/site/v1/register',$this->data(),$this->headers())->assertConflict()->assertJsonPath('code','ACCOUNT_EXISTS');
        $this->postJson('/api/site/v1/register',array_replace($this->data(),['document'=>'11144477735']),$this->headers())->assertConflict()->assertJsonPath('code','ACCOUNT_EXISTS');
        $this->assertDatabaseCount('users',1);
        $this->assertDatabaseCount('customers',1);
    }

    public function test_staff_credentials_never_issue_customer_api_tokens(): void
    {
        User::factory()->create(['email'=>'staff@example.test','password'=>'StaffPassword@2026','role'=>'central_admin','active'=>true]);
        $this->postJson('/api/site/v1/login',['email'=>'staff@example.test','password'=>'StaffPassword@2026'],$this->headers())->assertUnprocessable();
        $this->assertDatabaseCount('portal_tokens',0);
    }

    public function test_signing_requires_authenticated_pdf_review_and_is_idempotent(): void
    {
        $result=$this->registered(); $id=$result['proposal_id']; $headers=$this->headers($result['token']);
        $proposal=$this->getJson('/api/site/v1/proposals/'.$id,$headers)->assertOk()->json();
        $input=['accepted'=>1,'signer_name'=>'Tutor do site','fingerprint'=>$proposal['fingerprint'],'signature_data'=>$this->signatureData()];
        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',$input,$headers)->assertConflict();
        $this->get('/api/site/v1/proposals/'.$id.'/pdf',$headers)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',array_diff_key($input,['signature_data'=>true]),$headers)->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',$input,$headers+['X-Portal-IP'=>'200.1.2.3','X-Portal-Port'=>'40404'])->assertOk();
        $this->assertSame(['200.1.2.3',40404],[\App\Models\Proposal::findOrFail($id)->acceptance_evidence['ip'],\App\Models\Proposal::findOrFail($id)->acceptance_evidence['ip_port']]);
        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',$input,$headers)->assertOk();
        $this->assertDatabaseCount('subscriptions',1); $this->assertDatabaseCount('invoices',1); $this->assertDatabaseCount('payments',0);
        $doc=$this->postJson('/api/site/v1/proposals/'.$id.'/document',[],$headers)->assertOk()->json('document_id');
        $this->postJson('/api/site/v1/proposals/'.$id.'/document',[],$headers)->assertOk()->assertJsonPath('document_id',$doc);
        $this->get('/api/site/v1/documents/'.$doc,$headers)->assertOk()->assertHeader('Content-Type','application/pdf');
        $me=$this->getJson('/api/site/v1/me',$headers)->assertOk()->assertJsonPath('subscriptions.0.document_id',$doc)->json();
        $sid=$me['subscriptions'][0]['id'];
        $this->postJson('/api/site/v1/subscriptions/'.$sid.'/checkout',[],$headers)->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson('/api/site/v1/subscriptions/'.$sid.'/cancellation',['reason'=>'Solicito encerramento'],$headers)->assertOk();
        $this->postJson('/api/site/v1/subscriptions/'.$sid.'/cancellation',['reason'=>'Repetindo pedido'],$headers)->assertOk();
        $this->assertDatabaseCount('cancellation_requests',1);
    }

    public function test_large_drawn_signature_can_be_sent_but_other_routes_keep_the_small_limit(): void
    {
        $result=$this->registered(); $id=$result['proposal_id']; $headers=$this->headers($result['token']);
        $proposal=$this->getJson('/api/site/v1/proposals/'.$id,$headers)->assertOk()->json();
        $this->get('/api/site/v1/proposals/'.$id.'/pdf',$headers)->assertOk();
        // Um traço longo em tela de alta resolução gera um PNG bem maior que o de um desenho simples.
        $image=imagecreatetruecolor(220,150);
        for($y=0;$y<150;$y++) for($x=0;$x<220;$x++) imagesetpixel($image,$x,$y,imagecolorallocate($image,mt_rand(0,180),mt_rand(0,180),mt_rand(0,180)));
        ob_start(); imagepng($image); $signature='data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
        $this->assertGreaterThan(65536,strlen($signature)); $this->assertLessThan(300000,strlen($signature));

        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',['accepted'=>1,'signer_name'=>'Tutor do site','fingerprint'=>$proposal['fingerprint'],'signature_data'=>$signature],$headers)->assertOk();
        $this->assertDatabaseCount('subscriptions',1);

        $this->postJson('/api/site/v1/proposals/'.$id.'/accept',['signature_data'=>str_repeat('a',400001)],$headers)->assertStatus(413);
        $this->postJson('/api/site/v1/account-check',['email'=>str_repeat('a',65537)],$this->headers())->assertStatus(413);
    }

    public function test_another_customer_cannot_read_a_proposal_or_download_a_document(): void
    {
        $result=$this->registered();
        $customer=Customer::create(['name'=>'Other','document'=>'11144477735','email'=>'other@example.test','contact_verified_at'=>now()]);
        User::factory()->create(['email'=>'other@example.test','password'=>'OtherPassword@2026','role'=>'customer','active'=>true,'customer_id'=>$customer->id,'email_verified_at'=>now()]);
        $other=$this->postJson('/api/site/v1/login',['email'=>'other@example.test','password'=>'OtherPassword@2026'],$this->headers())->assertOk()->json('token');
        $this->getJson('/api/site/v1/proposals/'.$result['proposal_id'],$this->headers($other))->assertNotFound();
        $this->getJson('/api/site/v1/proposals/'.$result['proposal_id'].'/pdf',$this->headers($other))->assertNotFound();
    }

    public function test_logout_and_password_change_revoke_access(): void
    {
        $result=$this->registered();
        $this->postJson('/api/site/v1/logout',[],$this->headers($result['token']))->assertOk();
        $this->getJson('/api/site/v1/me',$this->headers($result['token']))->assertUnauthorized();
        $login=$this->postJson('/api/site/v1/login',['email'=>$this->data()['email'],'password'=>$this->data()['password']],$this->headers())->assertOk()->json('token');
        User::first()->update(['password'=>'AnotherPassword@2026']);
        $this->getJson('/api/site/v1/me',$this->headers($login))->assertUnauthorized();
    }

    public function test_email_verification_uses_institutional_link_and_does_not_expose_history_before_confirmation(): void
    {
        config(['plans.demo'=>false,'plans.commercial_approved'=>true]);
        $result=$this->registered(); $user=User::first();
        // O cadastro não manda e-mail nem trava: o cliente já vê o plano e pode seguir para o contrato.
        Notification::assertNothingSent();
        $this->getJson('/api/site/v1/me',$this->headers($result['token']))->assertOk()->assertJsonCount(1,'pets');
        $this->getJson('/api/site/v1/proposals/'.$result['proposal_id'],$this->headers($result['token']))->assertOk();
        $this->assertStringStartsWith('https://saofrancisco.vet.br/area-do-cliente/verificar-email/',(new PortalVerifyEmail)->toMail($user)->viewData['url']);
        $url=URL::temporarySignedRoute('site-api.verify-email',now()->addHour(),['user'=>$user->id,'hash'=>sha1($user->email)],false);
        // O link funciona sem a sessão do site (e-mail aberto em outro navegador) e um link adulterado é recusado.
        $this->getJson($url.'x',$this->headers())->assertForbidden();
        $loginUrl=$this->getJson($url,$this->headers())->assertOk()->json('login_url');
        $this->assertStringContainsString('/acesso/confirmado/'.$user->id,$loginUrl);
        // Link de entrada: leva ao portal do cliente uma única vez.
        $this->get($loginUrl)->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($user->fresh());
        auth()->logout();
        $this->get($loginUrl)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->getJson('/api/site/v1/me',$this->headers($result['token']))->assertOk()->assertJsonCount(1,'pets');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
