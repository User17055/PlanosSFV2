<?php
declare(strict_types=1);

use LuzPropria\LuzPanel\Kernel;
use LuzPropria\LuzPanel\Portal\{PortalApi,PortalResult};
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class PortalFakeApi extends PortalApi
{
    public array $calls=[];
    public bool $rejectRegistration=false;
    public bool $rejectLogin=false;
    public bool $unavailable=false;
    public bool $verified=true;
    public function __construct() {}
    public function managementUrl(): string { return 'https://gestao.test'; }
    public function paymentAllowed(string $url): bool { return $url==='https://app.vindi.com.br/bills/1'; }
    public function request(string $method,string $path,array $data=[],?string $token=null,array $context=[],bool $pdf=false): PortalResult
    {
        $this->calls[]=compact('method','path','data','token');
        if ($this->unavailable)return new PortalResult(503,['message'=>'A conexão está temporariamente indisponível.']);
        $offer=['id'=>1,'code'=>'SF-ESS','name'=>'Plano Essencial','category'=>'health_plan','description'=>'Cuidado','price_cents'=>9900,
            'enrollment_fee_cents'=>0,'billing_cycle'=>'monthly','duration_months'=>12,'demo'=>true,'species'=>['dog','cat'],
            'benefits'=>[['name'=>'Consulta','description'=>'Conforme contrato','waiting_days'=>30,'quantity_limit'=>2,'period_type'=>'contract_year','coverage_type'=>'included']]];
        if ($path==='catalog')return new PortalResult(200,['offers'=>[$offer]]);
        if ($path==='login')return $this->rejectLogin?new PortalResult(422,['errors'=>['email'=>['Confira seu e-mail e senha.']]]):new PortalResult(200,['token'=>str_repeat('a',64),'user'=>['verified'=>$this->verified]]);
        if ($path==='register')return $this->rejectRegistration?new PortalResult(422,['errors'=>['document'=>['Confira seu CPF.']]]):new PortalResult(201,['token'=>str_repeat('a',64),'proposal_id'=>1,'verification_sent'=>true,'user'=>['verified'=>$this->verified]]);
        if ($path==='me')return new PortalResult(200,['user'=>['name'=>'Tutor Exemplo','email'=>'tutor@example.test','verified'=>$this->verified],
            'demo'=>true,'financial'=>['status'=>'pending'],'subscriptions'=>[],'invoices'=>[],'proposals'=>[],'pets'=>[['id'=>1,'name'=>'Lua','species'=>'dog','breed'=>null,'birth_date'=>'2020-01-01']]]);
        if ($path==='proposals/1')return new PortalResult(200,['id'=>1,'number'=>'PR-1','name'=>'Plano Essencial','pet_name'=>'Lua','signer_name'=>'Tutor Exemplo',
            'price_cents'=>9900,'billing_cycle'=>'monthly','fingerprint'=>str_repeat('b',64),'accepted'=>false,'available'=>true,'document_id'=>null,'can_checkout'=>false]);
        if ($path==='proposals/1/pdf')return new PortalResult(200,[],'%PDF-1.7 test fixture');
        if ($path==='proposals')return new PortalResult(201,['id'=>1]);
        if ($path==='invoices/1/payment')return new PortalResult(200,['url'=>'https://app.vindi.com.br/bills/1']);
        if ($path==='logout')return new PortalResult(200,['ok'=>true]);
        if ($path==='attribution-preview')return new PortalResult(200,['referrer'=>($data['attribution_ref']??'')==='Ref+Cifrada='?'Vendedora Teste':null]);
        return new PortalResult(200,['message'=>'Concluído.']);
    }
}

class PortalTest extends TestCase
{
    private Kernel $kernel;
    private KernelBrowser $browser;
    private PortalFakeApi $api;

    protected function setUp(): void
    {
        $this->kernel=new Kernel('test',false);
        $this->kernel->boot();
        $this->api=new PortalFakeApi;
        $this->kernel->getContainer()->get('test.service_container')->set(PortalApi::class,$this->api);
        $this->browser=new KernelBrowser($this->kernel);
        $this->browser->disableReboot();
        $this->browser->catchExceptions(false);
    }
    protected function tearDown(): void { $this->kernel->shutdown(); parent::tearDown(); }
    private function csrf(string $path): string
    {
        $crawler=$this->browser->request('GET',$path);
        self::assertSame(200,$this->browser->getResponse()->getStatusCode());
        return (string)$crawler->filter('input[name="_token"]')->first()->attr('value');
    }
    private function login(): void
    {
        // O site não autentica mais: a sessão nasce do cadastro (o login fica na gestão).
        $csrf=$this->csrf('/contratar/SF-ESS');
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>$csrf,'name'=>'Tutor','password'=>'SecretPassword@2026','product_version_id'=>1]);
        self::assertSame(303,$this->browser->getResponse()->getStatusCode());
    }

    public function test_customer_pages_render_without_a_cms_database_connection(): void
    {
        $this->browser->request('GET','/area-do-cliente');
        self::assertSame('https://gestao.test/portal',$this->browser->getResponse()->headers->get('Location'));
        $this->browser->request('GET','/area-do-cliente/entrar');
        self::assertSame(302,$this->browser->getResponse()->getStatusCode());
        self::assertSame('https://gestao.test/entrar',$this->browser->getResponse()->headers->get('Location'));
    }

    public function test_login_is_not_served_by_the_site_and_never_reaches_management_api(): void
    {
        $this->browser->request('POST','/area-do-cliente/entrar',['email'=>'tutor@example.test','password'=>'PasswordNotPersisted@2026']);
        self::assertSame('https://gestao.test/entrar',$this->browser->getResponse()->headers->get('Location'));
        self::assertCount(0,$this->api->calls);
    }

    public function test_plan_selection_and_registration_remain_on_the_institutional_site(): void
    {
        $csrf=$this->csrf('/contratar/SF-ESS');
        self::assertStringContainsString('Plano Essencial',$this->browser->getResponse()->getContent());
        self::assertStringContainsString('R$ 99,00',$this->browser->getResponse()->getContent());
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>$csrf,'name'=>'Tutor','password'=>'SecretPassword@2026','product_version_id'=>999]);
        self::assertSame('/area-do-cliente/contratacoes/1',$this->browser->getResponse()->headers->get('Location'));
        $registration=array_values(array_filter($this->api->calls,fn($c)=>$c['path']==='register'))[0];
        self::assertSame(1,$registration['data']['product_version_id']);
        $this->browser->followRedirect();
        self::assertStringContainsString('Confirmar e assinar contrato',$this->browser->getResponse()->getContent());
    }

    public function test_whatsapp_form_sends_the_lead_with_the_saved_origin_and_never_blocks_the_visitor(): void
    {
        $this->browser->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('sf_origem','google|Campanha Plano|1'));
        $this->browser->request('POST','/whatsapp/lead',['nome'=>' Maria ','telefone'=>'(18) 99999-0000']);
        self::assertSame(204,$this->browser->getResponse()->getStatusCode());
        $call=end($this->api->calls);
        self::assertSame('leads',$call['path']);
        self::assertSame(['name'=>'Maria','phone'=>'(18) 99999-0000','origin'=>'google','campaign'=>'Campanha Plano','paid'=>true],$call['data']);
        self::assertNull($call['token']);

        $this->api->unavailable=true;
        $this->browser->request('POST','/whatsapp/lead',['nome'=>'Maria','telefone'=>'(18) 99999-0000']);
        self::assertSame(204,$this->browser->getResponse()->getStatusCode());
    }

    public function test_registration_link_shows_who_referred_and_forgot_password_goes_to_management(): void
    {
        $this->browser->request('GET','/contratar/SF-ESS?ref=Ref%2BCifrada%3D');
        self::assertStringContainsString('Indicado por',$this->browser->getResponse()->getContent());
        self::assertStringContainsString('Vendedora Teste',$this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('name="referrer_name"',$this->browser->getResponse()->getContent());
        $this->browser->request('GET','/area-do-cliente/esqueci-senha');
        self::assertSame('https://gestao.test/esqueci-senha',$this->browser->getResponse()->headers->get('Location'));
    }

    public function test_registration_link_reference_reaches_the_management_signup_once(): void
    {
        $csrf=$this->csrf('/contratar/SF-ESS?ref=Ref%2BCifrada%3D');
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>$csrf,'name'=>'Tutor','password'=>'SecretPassword@2026']);
        $register=array_values(array_filter($this->api->calls,fn($call)=>$call['path']==='register'));
        self::assertSame('Ref+Cifrada=',$register[0]['data']['attribution_ref']);
        // Lixo no endereço nunca vai para a gestão.
        $this->browser->getCookieJar()->clear();
        $csrf=$this->csrf('/contratar/SF-ESS?ref=%3Cscript%3E');
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>$csrf,'name'=>'Tutor','password'=>'SecretPassword@2026']);
        $register=array_values(array_filter($this->api->calls,fn($call)=>$call['path']==='register'));
        self::assertNull(end($register)['data']['attribution_ref']);
    }

    public function test_invalid_csrf_never_reaches_management_api(): void
    {
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>'invalid','name'=>'Tutor','password'=>'secret']);
        self::assertSame(419,$this->browser->getResponse()->getStatusCode());
        self::assertCount(0,$this->api->calls);
    }

    public function test_failed_registration_preserves_fields_without_persisting_passwords(): void
    {
        $this->api->rejectRegistration=true;
        $csrf=$this->csrf('/contratar/SF-ESS');
        $this->browser->request('POST','/contratar/SF-ESS',['_token'=>$csrf,'name'=>'Nome preservado','email'=>'tutor@example.test','password'=>'SecretPassword@2026','password_confirmation'=>'SecretPassword@2026']);
        $this->browser->followRedirect();
        $html=$this->browser->getResponse()->getContent();
        self::assertStringContainsString('Nome preservado',$html);
        self::assertStringContainsString('Confira seu CPF.',$html);
        self::assertStringNotContainsString('SecretPassword@2026',$html);
    }

    public function test_customer_home_lives_in_the_management_area_and_the_private_pdf_stays_on_the_site(): void
    {
        $this->login(); $this->browser->request('GET','/area-do-cliente');
        self::assertSame(302,$this->browser->getResponse()->getStatusCode());
        self::assertSame('https://gestao.test/portal',$this->browser->getResponse()->headers->get('Location'));
        $this->browser->request('GET','/area-do-cliente/contratacoes/1/conferencia.pdf');
        self::assertSame('application/pdf',$this->browser->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-',$this->browser->getResponse()->getContent());
        self::assertStringContainsString('no-store',$this->browser->getResponse()->headers->get('Cache-Control'));
    }

    public function test_logout_revokes_upstream_token_and_clears_institutional_session(): void
    {
        $this->login();
        $csrf=$this->csrf('/area-do-cliente/email-confirmado');
        $this->browser->request('POST','/area-do-cliente/sair',['_token'=>$csrf]);
        $this->browser->request('GET','/area-do-cliente/contratacoes/1/conferencia.pdf');
        self::assertSame('/area-do-cliente/entrar',$this->browser->getResponse()->headers->get('Location'));
        self::assertCount(1,array_filter($this->api->calls,fn($c)=>$c['path']==='logout'));
    }

    public function test_upstream_failure_is_shown_without_a_fabricated_dashboard(): void
    {
        $this->api->unavailable=true;
        $this->browser->request('GET','/contratar/SF-ESS');
        self::assertSame(503,$this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('temporariamente indisponível',$this->browser->getResponse()->getContent());
    }
}
