<?php
// Creates only fictitious data in the isolated DB_DATABASE supplied by portal_http.py.
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_contains((string)config('database.connections.sqlite.database'),'portal-http-')) throw new RuntimeException('Isolated test database required.');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
App\Models\Franchise::create(['code'=>'CENTRAL','name'=>'Unidade de teste','active'=>true]);
$product=App\Models\Product::create(['code'=>'SITE-HTTP','name'=>'Plano de teste HTTP','category'=>'health_plan','active'=>true]);
$version=$product->versions()->create(['version'=>1,'name'=>'Cuidado de teste HTTP','price_cents'=>9900,'billing_cycle'=>'monthly','species'=>['dog','cat'],
    'is_demo'=>true,'contract_template'=>'Contrato de {{contratante_nome}} para {{pet_nome}}. Condições fictícias de teste.']);
$version->benefitRules()->create(['code'=>'CONSULTA','name'=>'Consulta de teste','waiting_days'=>30,'quantity_limit'=>2,'period_type'=>'contract_year','coverage_type'=>'included']);
$version->update(['status'=>'published','published_at'=>now()]);
echo "Fixture prepared.\n";
