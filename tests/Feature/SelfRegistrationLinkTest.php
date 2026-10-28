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
