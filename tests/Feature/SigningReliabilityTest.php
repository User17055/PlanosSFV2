<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,Pet,Product,ProductVersion,Proposal,User};
use App\Services\Contracts\{ContractService,OriginalWordContract};
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SigningReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true]);
        Storage::fake('local');
    }

    private function proposal(string $method='digital'): Proposal
    {
        $franchise=Franchise::create(['name'=>'Unidade teste','code'=>'CENTRAL','active'=>true]);
        $customer=Customer::create(['name'=>'Titular '.Str::uuid(),'document'=>'52998224725','email'=>'titular@example.test','active'=>true,'contact_verified_at'=>now()]);
        $this->owner=User::factory()->create(['role'=>'customer','active'=>true,'customer_id'=>$customer->id,'email_verified_at'=>now()]);
        $this->admin=User::factory()->create(['role'=>'central_admin','active'=>true]);
        $pet=Pet::create(['customer_id'=>$customer->id,'name'=>'Lua','species'=>'dog','birth_date'=>'2020-01-01','active'=>true]);
        $product=Product::create(['name'=>'Plano de teste','code'=>'TEST','category'=>'health_plan','active'=>true]);
        $version=ProductVersion::create(['product_id'=>$product->id,'version'=>1,'name'=>'Versão teste','status'=>'published','published_at'=>now(),
            'price_cents'=>8600,'billing_cycle'=>'monthly','duration_months'=>12,'species'=>['dog'],
            'contract_layout'=>['source'=>'original-word-test'],'contract_template'=>'Titular: {{contratante_nome}}']);
        return app(ProposalService::class)->create(['customer_id'=>$customer->id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>$franchise->id,'channel'=>$method==='digital'?'site':'presential','signature_method'=>$method],$this->admin);
    }

    private function acceptance(Proposal $proposal): array
    {
        return ['accepted'=>1,'signer_name'=>$proposal->customer->name,'review_fingerprint'=>app(ProposalService::class)->reviewFingerprint($proposal),
            'signature_data'=>$this->signatureData(),'selfie_data'=>$this->selfieData()];
    }

    public function test_customer_from_the_site_signs_before_confirming_the_email(): void
    {
        // Pelo site o e-mail é confirmado depois do pagamento; a área do cliente não pode esconder a assinatura antes disso.
        $proposal=$this->proposal();
        $proposal->customer->update(['contact_verified_at'=>null]);
        $this->owner->update(['email_verified_at'=>null]);
        $this->actingAs($this->owner)->get(route('proposals.show',$proposal))->assertOk()->assertSee('name="signer_name"',false);
    }

    public function test_failed_review_and_incorrect_signer_cannot_commit_acceptance(): void
    {
        $proposal=$this->proposal();
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->once()->andThrow(new \RuntimeException('converter unavailable'));
        $this->actingAs($this->owner)->getJson(route('proposals.preview-pdf',$proposal))->assertStatus(503);
        $this->postJson(route('proposals.accept',$proposal),$this->acceptance($proposal))->assertUnprocessable()->assertJsonValidationErrors('contract_review');
        $data=$this->acceptance($proposal); $data['signer_name']='Outro titular';
        $this->postJson(route('proposals.accept',$proposal),$data)->assertUnprocessable()->assertJsonValidationErrors('signer_name');
        $this->assertNull($proposal->fresh()->accepted_at);
        $this->assertDatabaseCount('subscriptions',0);
        $this->assertDatabaseCount('invoices',0);
    }

    public function test_pdf_failure_recovers_with_original_acceptance_and_no_duplicate_records(): void
    {
        $proposal=$this->proposal();
        $calls=0; $finalContext=null;
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->andReturnUsing(function($snapshot,$context=[])use(&$calls,&$finalContext){
            $calls++;
            if($calls===2)throw new \RuntimeException('temporary conversion failure');
            if($context)$finalContext=$context;
            return "%PDF-1.4\noriginal-layout-test\n%%EOF";
        });
        $this->actingAs($this->owner)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $this->post(route('proposals.accept',$proposal),$this->acceptance($proposal))->assertRedirect(route('proposals.show',$proposal));
        $acceptedAt=$proposal->fresh()->accepted_at->toIso8601String();
        $this->assertNotNull($acceptedAt);
        $this->assertDatabaseCount('contract_documents',0);
        $this->assertSame(1,$calls,'The acceptance request must not wait for Word conversion.');
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('data-contract-recovery',false)->assertDontSee('name="signer_name"',false);
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertStatus(503)->assertJsonPath('ready',false);
        $this->travel(1)->days();
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        $document=$proposal->subscription()->firstOrFail()->documents()->sole();
        $this->assertSame($acceptedAt,$document->accepted_at->toIso8601String());
        $this->assertSame($acceptedAt,$finalContext['accepted_at']);
        $this->assertSame($proposal->customer->name,$document->evidence['signer_name']);
        $this->get(route('proposals.preview-pdf',$proposal))->assertOk()->assertContent(Storage::disk('local')->get($document->storage_path));
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        $this->assertDatabaseCount('subscriptions',1);
        $this->assertDatabaseCount('invoices',1);
        $this->assertDatabaseCount('contract_documents',1);
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Baixar contrato assinado');
    }

    public function test_blank_or_truncated_tablet_signature_is_rejected_before_reserving_plan(): void
    {
        $proposal=$this->proposal('tablet');
        $image=imagecreatetruecolor(160,60); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
        $this->actingAs($this->admin);
        foreach([$png,substr($png,0,30)] as $invalid) {
            $this->postJson(route('proposals.tablet-sign',$proposal),['signature_data'=>'data:image/png;base64,'.base64_encode($invalid)]+$this->acceptance($proposal))
                ->assertUnprocessable()->assertJsonValidationErrors('signature');
        }
        $this->assertNull($proposal->fresh()->accepted_at);
        $this->assertDatabaseCount('subscriptions',0);
        $this->assertDatabaseCount('pet_product_slots',0);
    }

    public function test_valid_tablet_signature_can_finish_and_staff_can_recover_pdf(): void
    {
        $proposal=$this->proposal('tablet');
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->andReturn("%PDF-1.4\ntest\n%%EOF");
        $image=imagecreatetruecolor(160,60); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagesetthickness($image,3); imageline($image,12,35,145,16,imagecolorallocate($image,11,89,136));
        ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
        $this->actingAs($this->admin)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $this->post(route('proposals.tablet-sign',$proposal),$this->acceptance($proposal)+['signature_data'=>'data:image/png;base64,'.base64_encode($png)])->assertRedirect();
        $this->assertDatabaseCount('contract_documents',0);
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        $subscription=$proposal->subscription()->firstOrFail();
        $this->assertSame('valid',$subscription->document_status);
        $this->assertSame('tablet',$subscription->documents()->sole()->signature_method);
        $sales=User::factory()->create(['role'=>'sales','active'=>true,'franchise_id'=>$proposal->originating_franchise_id]);
        $sales->franchises()->attach($proposal->originating_franchise_id,['active'=>true]);
        $this->actingAs($sales)->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        $other=User::factory()->create(['role'=>'customer','active'=>true,'customer_id'=>Customer::create(['name'=>'Outro tutor','document'=>'11144477735'])->id]);
        $this->actingAs($other)->postJson(route('proposals.regenerate-contract',$proposal))->assertForbidden();
    }

    public function test_review_is_cached_and_expired_proposal_has_no_signing_form(): void
    {
        $proposal=$this->proposal();
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->once()->andReturn("%PDF-1.4\ntest\n%%EOF");
        $this->actingAs($this->owner)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $this->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $proposal->update(['expires_at'=>now()->subMinute()]);
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Proposta vencida.')->assertDontSee('name="signer_name"',false);
    }

    public function test_consumed_invitation_reopens_only_for_authenticated_owner(): void
    {
        $proposal=$this->proposal();
        $service=app(ProposalService::class);
        $token=$service->issueLink($proposal);
        $service->accept($proposal,$this->owner,['accepted'=>true,'affirmative'=>true]);
        $this->travel(3)->days();
        $this->actingAs($this->owner)->get(route('sales.proposal',$token))->assertRedirect(route('proposals.show',$proposal));
        $this->actingAs($this->admin)->get(route('sales.proposal',$token))->assertNotFound();
    }

    public function test_paper_conversion_failure_can_be_retried_without_duplicate_reservation(): void
    {
        $proposal=$this->proposal('paper');
        $calls=0;
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->andReturnUsing(function()use(&$calls){
            if(++$calls===1)throw new \RuntimeException('converter busy');
            return "%PDF-1.4\npaper-test\n%%EOF";
        });
        $fingerprint=app(ProposalService::class)->reviewFingerprint($proposal);
        $this->actingAs($this->admin)->from(route('proposals.show',$proposal))
            ->post(route('proposals.issue-paper',$proposal),['review_fingerprint'=>$fingerprint])->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('subscriptions',1);
        $this->assertDatabaseCount('contract_documents',0);
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Tentar emitir PDF novamente');
        $this->post(route('proposals.issue-paper',$proposal),['review_fingerprint'=>$fingerprint])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('subscriptions',1);
        $this->assertDatabaseCount('invoices',1);
        $this->assertDatabaseCount('pet_product_slots',1);
        $this->assertDatabaseCount('contract_documents',1);
        $this->assertNull($proposal->fresh()->accepted_at);
    }

    public function test_final_document_uses_configured_private_disk_for_download_and_activation_checks(): void
    {
        Storage::fake('private-contracts');
        config(['integrations.contracts.disk'=>'private-contracts']);
        $proposal=$this->proposal();
        $subscription=app(ProposalService::class)->accept($proposal,$this->owner,['accepted'=>true,'affirmative'=>true]);
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->once()->andReturn("%PDF-1.4\nprivate-disk\n%%EOF");
        $document=app(ContractService::class)->generateDigital($subscription,['affirmative'=>true]);
        Storage::disk('private-contracts')->assertExists($document->storage_path);
        Storage::disk('local')->assertMissing($document->storage_path);
        $this->actingAs($this->owner)->get(route('contracts.download',$document))->assertOk()->assertHeader('Content-Type','application/pdf');
        $issues=app(\App\Services\Domain\EligibilityService::class)->activationImpediments($subscription->fresh());
        $this->assertNotContains('CONTRACT_PENDING',array_column($issues,'code'));
    }
}
