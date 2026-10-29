<?php

namespace Tests\Feature;

use App\Models\{Customer, ProductVersion, Proposal, User};
use App\Services\Contracts\{HealthPlanInstaller, LocalHealthPlanPreview};
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalPlanVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private array $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env','local');
        config(['plans.demo'=>true]);
        Storage::fake('local');
        $this->seed();
        $admin=User::where('role','central_admin')->firstOrFail();
        $this->versions=app(HealthPlanInstaller::class)->installAll();
        foreach($this->versions as $version) app(LocalHealthPlanPreview::class)->enable($version,$admin);
    }

    public function test_real_plans_load_on_staff_selection_without_fictitious_offers(): void
    {
        $this->actingAs(User::where('role','central_admin')->firstOrFail())->get(route('proposals.create'))
            ->assertOk()->assertViewHas('versions',fn($versions)=>$versions->count()===4 && $versions->every(fn($v)=>$v->status==='local_preview'));
    }

    public function test_imported_plan_continues_from_registration_to_review_and_final_contract_locally(): void
    {
        $version=$this->versions[1];
        $this->get(route('register',['product_version_id'=>$version->id]))->assertOk()->assertSee($version->name);
        $this->post(route('register.store'),[
            '_token'=>csrf_token(),
            'name'=>'Tutor do plano real','document'=>'52998224725','email'=>'visibilidade@example.test','phone'=>'18999990000',
            'address'=>'Rua do teste, 10','password'=>'TestePlanoReal@2026','password_confirmation'=>'TestePlanoReal@2026','privacy_accepted'=>1,
            'product_version_id'=>$version->id,'pet_name'=>'Pet do plano real','pet_species'=>'dog','pet_birth_date'=>'2020-01-01',
        ])->assertRedirect();
        $customer=Customer::where('email','visibilidade@example.test')->sole();
        $proposal=Proposal::where('customer_id',$customer->id)->sole();
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Tutor do plano real')->assertDontSee('{{contratante_nome}}');
        $this->get(route('proposals.preview-pdf',$proposal))->assertOk()->assertHeader('Content-Type','application/pdf');
        $other=User::factory()->create(['role'=>'customer','active'=>true,'customer_id'=>Customer::where('id','!=',$customer->id)->firstOrFail()->id]);
        $current=auth()->user();
        $this->actingAs($other)->get(route('proposals.preview-pdf',$proposal))->assertForbidden();
        $this->actingAs($current);
        $fingerprint=app(ProposalService::class)->reviewFingerprint($proposal);
        $this->post(route('proposals.accept',$proposal),['_token'=>csrf_token(),'accepted'=>1,'review_fingerprint'=>$fingerprint,'signature_data'=>$this->signatureData()])->assertRedirect();
        $this->postJson(route('proposals.regenerate-contract',$proposal),['_token'=>csrf_token()])->assertOk()->assertJsonPath('ready',true);
        $subscription=$proposal->subscription()->firstOrFail();
        $document=$subscription->documents()->where('kind','final_contract')->firstOrFail();
        $this->assertSame('valid',$document->status);
        $this->assertStringStartsWith('%PDF-',Storage::disk('local')->get($document->storage_path));
        $this->assertSame('local_preview',$version->fresh()->status);
        $this->assertFalse($subscription->invoices()->where('status','paid')->exists());
        $this->app->instance('env','production');
        $issues=app(\App\Services\Domain\EligibilityService::class)->activationImpediments($subscription);
        $this->assertContains('LOCAL_PREVIEW_ONLY',array_column($issues,'code'));
    }

    public function test_local_preview_cannot_be_offered_or_activated_as_an_offer_in_production(): void
    {
        $this->app->instance('env','production');
        config(['plans.demo'=>true,'plans.commercial_approved'=>true]);
        $this->assertFalse($this->versions[0]->fresh()->isOffered());
        $this->assertSame(0,ProductVersion::offered()->where('status','local_preview')->count());
        $this->get(route('register',['product_version_id'=>$this->versions[0]->id]))->assertStatus(422);
        $this->expectException(\App\Services\Domain\DomainException::class);
        app(LocalHealthPlanPreview::class)->enable($this->versions[0],User::where('role','central_admin')->firstOrFail());
    }
}
