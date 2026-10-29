<?php

namespace Tests\Feature;

use App\Models\{AttributionTouch,Franchise,MarketingLink,Pet,ProductVersion,Proposal,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true,'plans.financial_max_age_minutes'=>1440]);
        Storage::fake('local');
        $this->seed();
    }

    public function test_shared_link_origin_survives_login_and_is_preserved_in_the_contract_record(): void
    {
        $admin=User::where('role','central_admin')->firstOrFail();
        $sales=User::where('role','sales')->firstOrFail();
        $buyer=User::where('email','cliente@saofrancisco.local')->firstOrFail();
        $version=ProductVersion::whereHas('product',fn($q)=>$q->where('category','health_plan'))->firstOrFail();
        $this->actingAs($admin)->get(route('attribution_links.index'))->assertOk();
        $this->post(route('attribution_links.store'),[
            'name'=>'Instagram · Origem de homologação','acquisition_source'=>'instagram','owner_id'=>$sales->id,
            'originating_franchise_id'=>$sales->franchise_id,'product_version_id'=>$version->id,
            'campaign_name'=>'Campanha de homologação','utm_source'=>'instagram','utm_medium'=>'social','utm_campaign'=>'campanha-teste',
        ])->assertRedirect(route('attribution_links.index'));
        $link=MarketingLink::where('name','Instagram · Origem de homologação')->firstOrFail();
        $this->get(route('attribution_links.index'))->assertOk()->assertSee('Instagram · Origem de homologação');
        $this->post(route('logout'))->assertRedirect();
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))->assertRedirect(route('sales',['product_version_id'=>$version->id]));
        $this->get(route('sales',['product_version_id'=>$version->id]))->assertRedirect(config('site_portal.public_url').'/planos-saude/');
        $this->assertDatabaseCount('attribution_touches',1);
        $this->post(route('authenticate'),['email'=>$buyer->email,'password'=>'Demo@2026!'])->assertRedirect();
        $this->get(route('proposals.create'))->assertOk()->assertSee('Alguém indicou a São Francisco para você?')->assertDontSee('Origem informada');
        $pet=Pet::create(['customer_id'=>$buyer->customer_id,'name'=>'Pet de origem','species'=>'dog','birth_date'=>now()->subYears(2)->toDateString(),'active'=>true]);
        $this->post(route('proposals.store'),[
            'customer_id'=>$buyer->customer_id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>Franchise::where('code','ANA_JACINTA')->value('id'),
            'channel'=>'site','prefill_by'=>'customer','signature_method'=>'digital','link_owner_id'=>$admin->id,'utm_source'=>'forged',
        ])->assertRedirect();
        $proposal=Proposal::where('pet_id',$pet->id)->firstOrFail();
        $this->assertSame('instagram',$proposal->acquisition_source);
        $this->assertSame($link->id,$proposal->marketing_link_id);
        $this->assertSame($sales->id,$proposal->link_owner_id);
        $this->assertSame($sales->franchise_id,$proposal->originating_franchise_id);
        $this->assertSame('instagram',$proposal->utm_source);
        $this->assertSame($buyer->id,$proposal->created_by);
        $fingerprint=app(\App\Services\Domain\ProposalService::class)->reviewFingerprint($proposal);
        $this->post(route('proposals.accept',$proposal),['accepted'=>1,'review_fingerprint'=>$fingerprint,'signature_data'=>$this->signatureData()])->assertRedirect();
        $subscription=$proposal->subscription()->firstOrFail();
        $this->assertSame('instagram',$subscription->acquisition_source);
        $this->assertSame($proposal->attribution_snapshot,$subscription->attribution_snapshot);
        $this->assertSame('Campanha de homologação',$subscription->campaign_name);
    }

    public function test_salespeople_cannot_manage_another_units_links_or_read_reports(): void
    {
        $admin=User::where('role','central_admin')->firstOrFail();
        $sales=User::where('role','sales')->firstOrFail();
        $other=Franchise::where('code','ANA_JACINTA')->firstOrFail();
        $link=app(\App\Services\Attribution\MarketingLinkService::class)->create(['name'=>'Link de outra unidade','originating_franchise_id'=>$other->id,'owner_id'=>$admin->id],$admin)['link'];
        $this->actingAs($sales)->get(route('attribution_links.index'))->assertOk()->assertDontSee('Link de outra unidade');
        $this->post(route('attribution_links.revoke',$link))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.export'))->assertForbidden();
    }

    public function test_revoked_shared_link_stops_new_visits_without_erasing_its_history(): void
    {
        $admin=User::where('role','central_admin')->firstOrFail();
        $unit=Franchise::where('code','MANOEL_GOULART')->firstOrFail();
        $created=app(\App\Services\Attribution\MarketingLinkService::class)->create(['name'=>'Link temporário','originating_franchise_id'=>$unit->id],$admin);
        $link=$created['link'];
        $url=route('attribution_links.visit',['token'=>$created['token']]);
        $this->get($url)->assertRedirect();
        $this->assertDatabaseCount('attribution_touches',1);
        $this->actingAs($admin)->post(route('attribution_links.revoke',$link))->assertRedirect();
        $this->post(route('logout'))->assertRedirect();
        $this->get($url)->assertNotFound();
        $this->assertDatabaseCount('attribution_touches',1);
    }
}
