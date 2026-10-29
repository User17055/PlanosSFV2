<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,Pet,Product,ProductVersion,Proposal,User};
use App\Notifications\{ContractCopy,ProposalLink};
use App\Services\Contracts\OriginalWordContract;
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\{Notification,Storage};
use Tests\TestCase;

class SigningEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>true]);
        Storage::fake('local');
        Notification::fake();
        $this->mock(OriginalWordContract::class)->shouldReceive('pdf')->andReturn("%PDF-1.4\nevidence-test\n%%EOF");
    }

    private function proposal(string $channel='site',string $method='digital'): Proposal
    {
        $franchise=Franchise::create(['name'=>'Unidade teste','code'=>'CENTRAL','active'=>true]);
        $customer=Customer::create(['name'=>'Titular Evidência','document'=>'52998224725','email'=>'titular@example.test','active'=>true,'contact_verified_at'=>now()]);
        $this->owner=User::factory()->create(['role'=>'customer','active'=>true,'customer_id'=>$customer->id,'email_verified_at'=>now()]);
        $this->admin=User::factory()->create(['role'=>'central_admin','active'=>true]);
        $pet=Pet::create(['customer_id'=>$customer->id,'name'=>'Lua','species'=>'dog','birth_date'=>'2020-01-01','active'=>true]);
        $product=Product::create(['name'=>'Plano de teste','code'=>'TEST','category'=>'health_plan','active'=>true]);
        $version=ProductVersion::create(['product_id'=>$product->id,'version'=>1,'name'=>'Versão teste','status'=>'published','published_at'=>now(),
            'price_cents'=>8600,'billing_cycle'=>'monthly','duration_months'=>12,'species'=>['dog'],
            'contract_layout'=>['source'=>'original-word-test'],'contract_template'=>'Titular: {{contratante_nome}}']);
        return app(ProposalService::class)->create(['customer_id'=>$customer->id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>$franchise->id,'channel'=>$channel,'signature_method'=>$method],$this->admin);
    }

    private function acceptance(Proposal $proposal): array
    {
        return ['accepted'=>1,'signer_name'=>$proposal->customer->name,'review_fingerprint'=>app(ProposalService::class)->reviewFingerprint($proposal)];
    }

    public function test_remote_acceptance_requires_drawn_signature_besides_confirmation(): void
    {
        $proposal=$this->proposal();
        $this->actingAs($this->owner)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $this->postJson(route('proposals.accept',$proposal),$this->acceptance($proposal))->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->postJson(route('proposals.accept',$proposal),['signature_data'=>$this->signatureData()]+array_diff_key($this->acceptance($proposal),['accepted'=>1]))
            ->assertUnprocessable()->assertJsonValidationErrors('accepted');
        $this->assertNull($proposal->fresh()->accepted_at);
        $this->assertDatabaseCount('subscriptions',0);
    }

    public function test_remote_signature_records_device_and_network_then_emails_copy_and_goes_to_payment(): void
    {
        $proposal=$this->proposal();
        $this->actingAs($this->owner)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $device=json_encode(['platform'=>'Android','mobile'=>true,'screen'=>'412x915','timezone'=>'America/Sao_Paulo','unexpected'=>'ignored']);
        $this->withServerVariables(['REMOTE_ADDR'=>'177.10.20.30','REMOTE_PORT'=>'51234'])->withHeader('User-Agent','Mozilla/5.0 (Linux; Android 14)')
            ->post(route('proposals.accept',$proposal),$this->acceptance($proposal)+['signature_data'=>$this->signatureData(),'device_info'=>$device])
            ->assertRedirect(route('proposals.show',$proposal));
        $evidence=$proposal->fresh()->acceptance_evidence;
        $this->assertSame(64,strlen($evidence['signature_hash']));
        $this->assertSame('177.10.20.30',$evidence['ip']);
        $this->assertSame(51234,$evidence['ip_port']);
        $this->assertStringContainsString('Android 14',$evidence['user_agent']);
        $this->assertSameIgnoringKeyOrder(['screen'=>'412x915','platform'=>'Android','mobile'=>true,'timezone'=>'America/Sao_Paulo'],$evidence['device']);
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        $this->postJson(route('proposals.regenerate-contract',$proposal))->assertOk();
        Notification::assertSentOnDemandTimes(ContractCopy::class,1);
        Notification::assertSentOnDemand(ContractCopy::class,fn($notification,$channels,AnonymousNotifiable $notifiable)=>$notifiable->routes['mail']==='titular@example.test');
        $this->assertNotNull($proposal->subscription->documents()->sole()->customer_notified_at);
        $this->get(route('proposals.show',$proposal))->assertOk();
        $this->assertFalse(session()->has('checkout_after_signing.'.$proposal->id),'The payment redirect is offered once the contract is ready.');
    }

    public function test_in_person_tablet_signature_requires_face_photo_kept_privately(): void
    {
        $proposal=$this->proposal('presential','tablet');
        $this->actingAs($this->admin)->get(route('proposals.preview-pdf',$proposal))->assertOk();
        $this->postJson(route('proposals.tablet-sign',$proposal),$this->acceptance($proposal)+['signature_data'=>$this->signatureData()])
            ->assertUnprocessable()->assertJsonValidationErrors('selfie_data');
        $this->postJson(route('proposals.tablet-sign',$proposal),$this->acceptance($proposal)+['signature_data'=>$this->signatureData(),'selfie_data'=>'data:image/png;base64,AAAA'])
            ->assertUnprocessable()->assertJsonValidationErrors('selfie');
        $this->assertDatabaseCount('subscriptions',0);
        $this->post(route('proposals.tablet-sign',$proposal),$this->acceptance($proposal)+['signature_data'=>$this->signatureData(),'selfie_data'=>$this->selfieData()])->assertRedirect();
        $evidence=$proposal->fresh()->acceptance_evidence;
        Storage::disk('local')->assertExists($evidence['selfie_path']);
        $this->assertSame($this->admin->id,$evidence['collected_by']);
        $this->get(route('proposals.selfie',$proposal))->assertOk()->assertHeader('Content-Type','image/jpeg');
        $this->get(route('proposals.show',$proposal))->assertOk()->assertSee('Foto do titular');
        $this->actingAs($this->owner)->get(route('proposals.selfie',$proposal))->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('proposals.regenerate-contract',$proposal))->assertOk()->assertJsonPath('ready',true);
        Notification::assertSentOnDemandTimes(ContractCopy::class,1);
        $this->actingAs($this->owner)->get(route('portal.pet',$proposal->pet_id))->assertOk()->assertSee('Baixar contrato');
    }

    public function test_link_proposal_is_emailed_to_customer(): void
    {
        $proposal=$this->proposal('link');
        $this->actingAs($this->admin)->post(route('proposals.link',$proposal))->assertRedirect()->assertSessionHas('success',fn($message)=>str_contains($message,'titular@example.test'));
        Notification::assertSentOnDemand(ProposalLink::class,fn($notification,$channels,AnonymousNotifiable $notifiable)=>$notifiable->routes['mail']==='titular@example.test');
    }
}
