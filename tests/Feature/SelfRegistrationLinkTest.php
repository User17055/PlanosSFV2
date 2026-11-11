<?php

namespace Tests\Feature;

use App\Models\{Customer, MarketingLink, Pet, ProductVersion, Proposal, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SelfRegistrationLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['plans.demo'=>true]);
        $this->seed();
    }

    public function test_staff_generates_a_link_without_creating_or_selecting_tutor_pet_or_proposal(): void
    {
        $staff = User::where('role','sales')->firstOrFail();
        $version = ProductVersion::where('status','published')->firstOrFail();
        $before = [Customer::count(),Pet::count(),Proposal::count()];
        $this->actingAs($staff)->get(route('proposals.create',['channel'=>'link','prefill_by'=>'customer']))->assertOk();
        $response = $this->post(route('proposals.store'),[
            'channel'=>'link','prefill_by'=>'customer','signature_method'=>'digital',
            'product_version_id'=>$version->id,'originating_franchise_id'=>$staff->franchise_id,
        ]);
        $link=MarketingLink::where('self_registration',true)->sole();
        $response->assertRedirect(route('proposals.registration-link',$link));
        $this->get(route('proposals.registration-link',$link))->assertOk()->assertSee('Envie o link para o tutor');
        $this->assertSame($before,[Customer::count(),Pet::count(),Proposal::count()]);
        $this->post(route('logout'));
        // Sem o site ligado, o cadastro continua sendo o da gestão.
        config(['site_portal.enabled'=>false]);
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))
            ->assertRedirect(route('register',['product_version_id'=>$version->id]));
        $this->get(route('register',['product_version_id'=>$version->id]))->assertOk()->assertSee('Ler contrato antes de continuar');
        $this->get(route('sales.contract',$version))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->post(route('register.store'),[
            'product_version_id'=>$version->id,'name'=>'Tutor Pelo Link','document'=>'52998224725',
            'email'=>'link.tutor@example.test','phone'=>'18999990000','address'=>'Rua do Tutor, 10',
            'password'=>'PeloLinkTeste@2026','password_confirmation'=>'PeloLinkTeste@2026','privacy_accepted'=>1,
            'pet_name'=>'Pet Pelo Link','pet_species'=>'dog','pet_birth_date'=>'2020-01-01',
        ])->assertRedirect();
        $customer=Customer::where('email','link.tutor@example.test')->sole();
        $proposal=Proposal::where('customer_id',$customer->id)->sole();
        $this->assertSame($link->id,$proposal->marketing_link_id);
        $this->assertSame($staff->id,$proposal->link_owner_id);
        $this->assertSame($staff->franchise_id,$proposal->originating_franchise_id);
        $this->assertNull($proposal->accepted_at);
    }

    public function test_the_campaign_marked_by_the_seller_goes_with_the_link_even_against_the_whole_site_one(): void
    {
        $staff = User::where('role','sales')->firstOrFail();
        $version = ProductVersion::where('status','published')->where('billing_cycle','monthly')->firstOrFail();
        $today = now('America/Sao_Paulo');
        $site = \App\Models\Campaign::create(['name'=>'Site todo','free_months'=>1,'starts_on'=>$today->copy()->subDay()->toDateString(),'ends_on'=>$today->copy()->addDays(5)->toDateString(),'whole_site'=>true,'active'=>true]);
        $special = \App\Models\Campaign::create(['name'=>'Só pelo vendedor','free_months'=>2,'starts_on'=>$today->copy()->subDay()->toDateString(),'whole_site'=>false,'active'=>true]);
        config(['site_portal.enabled'=>false]);
        $register = function (?int $campaignId, string $email, string $document) use ($staff, $version) {
            $this->actingAs($staff)->get(route('proposals.create',['channel'=>'link','prefill_by'=>'customer']))->assertOk()->assertSee('Campanha do cliente');
            $this->post(route('proposals.store'),['channel'=>'link','prefill_by'=>'customer','signature_method'=>'digital',
                'product_version_id'=>$version->id,'originating_franchise_id'=>$staff->franchise_id,'campaign_id'=>$campaignId])->assertRedirect();
            $link = MarketingLink::where('self_registration',true)->latest('id')->first();
            $this->assertSame($campaignId, $link->campaign_id);
            $this->post(route('logout'));
            $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect();
            $this->post(route('register.store'),['product_version_id'=>$version->id,'name'=>'Tutor Pelo Link','document'=>$document,
                'email'=>$email,'phone'=>'18999990000','address'=>'Rua do Tutor, 10','password'=>'PeloLinkTeste@2026','password_confirmation'=>'PeloLinkTeste@2026',
                'privacy_accepted'=>1,'pet_name'=>'Pet Pelo Link','pet_species'=>'dog','pet_birth_date'=>'2020-01-01'])->assertRedirect();
            $this->post(route('logout'));
            return Proposal::where('customer_id',Customer::where('email',$email)->sole()->id)->sole();
        };
        $chosen = $register($special->id, 'campanha.link@example.test', '52998224725');
        $this->assertSame([$special->id, 2], [$chosen->campaign_id, $chosen->free_months]);
        // "Sem campanha" no link vale mesmo com a campanha do site todo em vigor.
        $none = $register(null, 'sem.campanha@example.test', '11144477735');
        $this->assertSame([null, 0], [$none->campaign_id, $none->free_months]);
        $this->assertNotNull($site->id);
    }

    public function test_link_becomes_used_after_someone_signs_and_only_that_person_gets_back_in(): void
    {
        $staff = User::where('role','sales')->firstOrFail();
        $version = ProductVersion::where('status','published')->firstOrFail();
        config(['site_portal.enabled'=>false]);
        $this->actingAs($staff)->post(route('proposals.store'),['channel'=>'link','prefill_by'=>'customer','signature_method'=>'digital',
            'product_version_id'=>$version->id,'originating_franchise_id'=>$staff->franchise_id]);
        $link = MarketingLink::where('self_registration',true)->sole();
        $this->post(route('logout'));
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect();
        $this->post(route('register.store'),['product_version_id'=>$version->id,'name'=>'Primeira Pessoa','document'=>'52998224725',
            'email'=>'primeira@example.test','phone'=>'18999990000','address'=>'Rua do Tutor, 10','password'=>'PeloLinkTeste@2026','password_confirmation'=>'PeloLinkTeste@2026',
            'privacy_accepted'=>1,'pet_name'=>'Pet Um','pet_species'=>'dog','pet_birth_date'=>'2020-01-01'])->assertRedirect();
        $this->post(route('logout'));
        // Antes de assinar, o link continua aberto.
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect();
        \Illuminate\Support\Facades\DB::table('proposals')->where('marketing_link_id',$link->id)->update(['accepted_at'=>now()]);
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertStatus(410)->assertSee('Este link já foi usado');
        $owner = User::where('email','primeira@example.test')->sole();
        $this->actingAs($owner)->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect(route('portal'));
        $this->post(route('logout'));
        $this->actingAs($staff)->get(route('attribution_links.index'))->assertOk()->assertSee('Usado');
    }

    public function test_registration_link_opens_the_site_signup_and_keeps_the_seller(): void
    {
        $key=str_repeat('test-site-key-',3);
        config(['site_portal.enabled'=>true,'site_portal.key'=>$key,'site_portal.public_url'=>'https://saofrancisco.vet.br']);
        $staff=User::where('role','sales')->firstOrFail();
        $version=ProductVersion::where('status','published')->firstOrFail();
        $this->actingAs($staff)->post(route('proposals.store'),['channel'=>'link','prefill_by'=>'customer','signature_method'=>'digital',
            'product_version_id'=>$version->id,'originating_franchise_id'=>$staff->franchise_id]);
        $link=MarketingLink::where('self_registration',true)->sole();
        $this->post(route('logout'));

        // Mesmo endereço de Planos → Contratar, com a origem criptografada (ilegível no navegador).
        $target=$this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect()->headers->get('Location');
        $prefix='https://saofrancisco.vet.br/contratar/'.$version->product->code.'?ref=';
        $this->assertStringStartsWith($prefix,$target);
        $ref=rawurldecode(substr($target,strlen($prefix)));
        $this->assertStringNotContainsString('visitor_key',base64_decode($ref));

        $data=fn(string $email,string $document,?string $reference)=>['name'=>'Tutor do Site','document'=>$document,'email'=>$email,'phone'=>'18999990000',
            'street'=>'Rua de teste','number'=>'10','neighborhood'=>'Centro','city'=>'Presidente Prudente','state'=>'SP','zip'=>'19000000',
            'password'=>'SitePassword@2026','password_confirmation'=>'SitePassword@2026','privacy_accepted'=>1,
            'pet_name'=>'Lua','pet_species'=>'dog','pet_birth_date'=>'2020-01-01','product_version_id'=>$version->id,'attribution_ref'=>$reference];
        // O site mostra quem indicou, e o que o cliente digitar como origem ou indicação não substitui o link.
        $this->postJson('/api/site/v1/attribution-preview',['attribution_ref'=>$ref],['X-Site-Key'=>$key])->assertOk()->assertJson(['referrer'=>$staff->name]);
        $this->postJson('/api/site/v1/attribution-preview',['attribution_ref'=>'lixo'],['X-Site-Key'=>$key])->assertOk()->assertJson(['referrer'=>null]);
        $this->postJson('/api/site/v1/register',$data('link.site@example.test','52998224725',$ref)+['acquisition_source'=>'google','referrer_name'=>'Outra Pessoa'],['X-Site-Key'=>$key])->assertCreated();
        $proposal=Proposal::whereHas('customer',fn($q)=>$q->where('email','link.site@example.test'))->sole();
        $this->assertSame([$link->id,$staff->id],[$proposal->marketing_link_id,$proposal->link_owner_id]);
        $this->assertNotSame('Outra Pessoa',$proposal->referrer_name);
        $this->assertNotSame('google',$proposal->acquisition_source);

        // Referência adulterada não atribui a venda a ninguém, e o cadastro continua.
        $this->postJson('/api/site/v1/register',$data('sem.vendedor@example.test','11144477735',substr_replace($ref,$ref[40]==='A'?'B':'A',40,1)),['X-Site-Key'=>$key])->assertCreated();
        $plain=Proposal::whereHas('customer',fn($q)=>$q->where('email','sem.vendedor@example.test'))->sole();
        $this->assertSame([null,null],[$plain->marketing_link_id,$plain->link_owner_id]);
    }

    public function test_seller_prefill_still_requires_existing_tutor_and_pet(): void
    {
        $staff = User::where('role','sales')->firstOrFail();
        $this->actingAs($staff)->postJson(route('proposals.store'),[
            'channel'=>'link','prefill_by'=>'seller','signature_method'=>'digital',
            'product_version_id'=>ProductVersion::where('status','published')->value('id'),
            'originating_franchise_id'=>$staff->franchise_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id','pet_id']);
        $this->assertDatabaseCount('marketing_links',0);
    }

    public function test_link_generation_does_not_bypass_unit_scope(): void
    {
        $staff=User::where('role','sales')->firstOrFail();
        $this->actingAs($staff)->postJson(route('proposals.store'),[
            'channel'=>'link','prefill_by'=>'customer','signature_method'=>'digital',
            'product_version_id'=>ProductVersion::where('status','published')->value('id'),
            'originating_franchise_id'=>\App\Models\Franchise::where('code','ANA_JACINTA')->value('id'),
        ])->assertForbidden();
        $this->assertDatabaseCount('marketing_links',0);
    }

    public function test_staff_can_open_contract_and_acceptance_preview_without_creating_or_signing_records(): void
    {
        $staff=User::where('role','sales')->firstOrFail();
        $version=ProductVersion::where('status','published')->firstOrFail();
        $created=app(\App\Services\Attribution\MarketingLinkService::class)->create([
            'name'=>'Link para conferir contrato','originating_franchise_id'=>$staff->franchise_id,
            'product_version_id'=>$version->id,'self_registration'=>true,
        ],$staff);
        $before=[Customer::count(),Pet::count(),Proposal::count(),\App\Models\ContractDocument::count(),\App\Models\AttributionTouch::count()];
        $this->actingAs($staff)->get(route('attribution_links.visit',['token'=>$created['token']]))
            ->assertRedirect(route('attribution_links.contract-preview',['token'=>$created['token']]));
        $this->get(route('attribution_links.contract-preview',['token'=>$created['token']]))
            ->assertOk()->assertSee('Contrato e assinatura')->assertSee('Confirmar assinatura e gerar contrato')->assertSee('{{contratante_nome}}')->assertSee('disabled',false);
        $this->get(route('attribution_links.contract-preview-pdf',['token'=>$created['token']]))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertSame($before,[Customer::count(),Pet::count(),Proposal::count(),\App\Models\ContractDocument::count(),\App\Models\AttributionTouch::count()]);
    }

    public function test_staff_preview_does_not_grant_access_to_another_units_link(): void
    {
        $admin=User::where('role','central_admin')->firstOrFail();
        $sales=User::where('role','sales')->firstOrFail();
        $created=app(\App\Services\Attribution\MarketingLinkService::class)->create([
            'name'=>'Link de outra unidade','originating_franchise_id'=>\App\Models\Franchise::where('code','ANA_JACINTA')->value('id'),
            'product_version_id'=>ProductVersion::where('status','published')->value('id'),'self_registration'=>true,
        ],$admin);
        $this->actingAs($sales)->get(route('attribution_links.contract-preview',['token'=>$created['token']]))->assertForbidden();
        $this->get(route('attribution_links.contract-preview-pdf',['token'=>$created['token']]))->assertForbidden();
    }

    public function test_public_contract_consultation_does_not_expose_unavailable_drafts(): void
    {
        $draft=app(\App\Services\Contracts\EconomicContractInstaller::class)->install();
        $this->get(route('sales.contract',$draft))->assertNotFound();
    }
}
