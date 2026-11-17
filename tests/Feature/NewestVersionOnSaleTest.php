<?php

namespace Tests\Feature;

use App\Models\{BenefitRule, Franchise, Product, ProductVersion, User};
use App\Services\Attribution\MarketingLinkService;
use App\Services\Domain\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewestVersionOnSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Franchise $franchise;
    private ProductVersion $v1;

    protected function setUp(): void
    {
        parent::setUp();
        Franchise::create(['name'=>'Canal central','code'=>'CENTRAL']);
        $this->franchise=Franchise::create(['name'=>'Unidade A','code'=>'A']);
        $this->admin=User::create(['name'=>'Admin','email'=>'admin@versao.test','password'=>'Test@2026!','role'=>'central_admin','active'=>true]);
        $product=Product::create(['name'=>'Plano','code'=>'VERSAO','category'=>'health_plan']);
        $this->v1=$this->version($product,1,'Contrato antigo.');
    }

    private function version(Product $product,int $number,string $contract,array $extra=[]): ProductVersion
    {
        $version=ProductVersion::create($extra+['product_id'=>$product->id,'version'=>$number,'name'=>'Plano','price_cents'=>10000,'billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>$contract]);
        BenefitRule::create(['product_version_id'=>$version->id,'code'=>'CONS','name'=>'Consulta','waiting_days'=>0,'quantity_limit'=>2,'period_type'=>'contract_year']);
        return app(CatalogService::class)->publish($version,$this->admin);
    }

    public function test_publishing_a_new_version_takes_the_previous_one_off_sale(): void
    {
        ['link'=>$link]=app(MarketingLinkService::class)->create(['name'=>'Link antigo','originating_franchise_id'=>$this->franchise->id,'owner_id'=>$this->admin->id,'product_version_id'=>$this->v1->id,'acquisition_source'=>'shared_link'],$this->admin);
        $v2=$this->version($this->v1->product,2,'Contrato novo.');

        $this->assertSame([$v2->id],ProductVersion::preferredOffers()->pluck('id')->all());
        $this->assertFalse($this->v1->fresh()->isOffered());
        $this->assertTrue($v2->fresh()->isOffered());
        $this->assertSame($v2->id,$this->v1->currentOffer()->id);

        // Nova contratação lista o plano uma vez só, e um id antigo marca a versão nova.
        $this->actingAs($this->admin)->get(route('proposals.create',['product_version_id'=>$this->v1->id]))->assertOk()
            ->assertSee('value="'.$v2->id.'" checked',false)
            ->assertDontSee('name="product_version_id" value="'.$this->v1->id.'"',false);
        $this->actingAs($this->admin)->get(route('attribution_links.index'))->assertOk()->assertDontSee('Plano · v1');
        auth()->logout();

        // Link criado antes da publicação segue para a versão nova.
        $this->get(route('attribution_links.visit',['token'=>$link->token_encrypted]))
            ->assertRedirect(route('sales',['product_version_id'=>$v2->id]))->assertSessionHas('sf_suggested_product_version_id',$v2->id);
        $this->get(route('register',['product_version_id'=>$this->v1->id]))->assertOk()->assertViewHas('selectedVersion',fn($v)=>$v->id===$v2->id);
        $this->get(route('sales.contract',$this->v1))->assertRedirect(route('sales.contract',$v2));
    }

    public function test_a_version_scheduled_for_later_keeps_the_current_one_on_sale(): void
    {
        $v2=$this->version($this->v1->product,2,'Contrato novo.',['offer_starts_at'=>now()->addDay()]);

        $this->assertSame([$this->v1->id],ProductVersion::preferredOffers()->pluck('id')->all());
        $this->assertTrue($this->v1->fresh()->isOffered());
        $this->assertFalse($v2->fresh()->isOffered());

        $this->travel(2)->days();
        $this->assertSame([$v2->id],ProductVersion::preferredOffers()->pluck('id')->all());
        $this->assertSame($v2->id,$this->v1->currentOffer()->id);
    }
}
