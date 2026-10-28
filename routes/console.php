<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\{IntegrationEvent,Subscription};
use App\Jobs\{ProcessVindiWebhookJob,ReconcileVindiSubscriptionJob,SyncPartnerSubscriptionJob};

Artisan::command('plans:preview-health-contracts', function () {
    $actor=\App\Models\User::where('role','central_admin')->where('active',true)->firstOrFail();
    $versions=\App\Models\ProductVersion::whereHas('product',fn($q)=>$q->whereIn('code',array_column(\App\Services\Contracts\HealthPlanInstaller::PLANS,1)))
        ->where('is_demo',false)->whereNotNull('contract_layout')->get();
    foreach($versions as $version) {
        app(\App\Services\Contracts\LocalHealthPlanPreview::class)->enable($version,$actor);
        $this->info($version->name.' disponível para teste local.');
    }
})->purpose('Disponibilizar os planos importados somente no ambiente local demonstrativo');

Artisan::command('plans:install-health-contracts', function () {
    foreach(app(\App\Services\Contracts\HealthPlanInstaller::class)->installAll() as $version) {
        $this->info($version->name.' · R$ '.number_format($version->price_cents/100,2,',','.').' · versão '.$version->id.' · '.$version->status);
    }
})->purpose('Instalar os quatro planos reais e seus contratos originais com campos automáticos');

Artisan::command('plans:install-economic-contract', function () {
    $version = app(\App\Services\Contracts\EconomicContractInstaller::class)->install();
    $this->info('Modelo Econômico instalado no catálogo: versão '.$version->id.'.');
    $this->info('O rascunho conserva os valores e benefícios do documento. Descontos dependem de implementação antes da publicação.');
})->purpose('Instalar o contrato Econômico com campos de preenchimento, sem alterar contratos existentes');

Artisan::command('plans:recover-events', function () {
    IntegrationEvent::whereIn('status',['pending','error'])->where('attempts','<',8)->chunkById(100,function($events) {
        foreach($events as $event) {
            if($event->provider==='vindi') ProcessVindiWebhookJob::dispatch($event->id);
            elseif(in_array($event->provider,['simplesvet','tudoprapet'],true)) SyncPartnerSubscriptionJob::dispatch($event->id);
        }
    });
    $this->info('Eventos pendentes encaminhados às filas.');
})->purpose('Recuperar inbox e outbox persistidas sem duplicar efeitos');

Artisan::command('plans:reconcile',function() {
    // Includes contracts whose entry was charged but whose monthly subscription is not open yet.
    Subscription::where(fn($q)=>$q->whereNotNull('vindi_subscription_id')->orWhereHas('invoices',fn($i)=>$i->where('is_initial',true)->whereNotNull('external_id')))
        ->chunkById(100,function($subscriptions) {
        foreach($subscriptions as $subscription) ReconcileVindiSubscriptionJob::dispatch($subscription->id);
    });
    $this->info('Conciliação encaminhada à fila financeira.');
})->purpose('Conferir informações financeiras no provedor autorizado');

Schedule::command('plans:recover-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('plans:reconcile')->everyFifteenMinutes()->withoutOverlapping();
Artisan::command('plans:finalize',function() {
    $this->info(app(\App\Services\Domain\CancellationService::class)->finalizeDue().' vínculos encerrados.');
})->purpose('Efetivar encerramentos e liberar vagas na data contratada');
Schedule::command('plans:finalize')->everyMinute()->withoutOverlapping();

Artisan::command('plans:bootstrap-admin',function() {
    if(\App\Models\User::where('role','central_admin')->exists()) {
        $this->error('Já existe um administrador central. Gerencie novos acessos pela área Rede e acessos.');
        return 1;
    }
    $name=$this->ask('Nome do responsável');
    $email=$this->ask('E-mail de acesso');
    $password=$this->secret('Senha inicial com pelo menos 12 caracteres');
    $validator=\Illuminate\Support\Facades\Validator::make(compact('name','email','password'),['name'=>'required|string|min:3|max:180','email'=>'required|email|unique:users,email','password'=>'required|string|min:12']);
    if($validator->fails()) { foreach($validator->errors()->all() as $error) $this->error($error); return 1; }
    \Illuminate\Support\Facades\DB::transaction(function()use($name,$email,$password) {
        \App\Models\Franchise::firstOrCreate(['code'=>'CENTRAL'],['name'=>'Canal central','active'=>true]);
        $user=\App\Models\User::create(['name'=>$name,'email'=>$email,'password'=>$password,'role'=>'central_admin','active'=>true]);
        \App\Models\AuditLog::create(['actor_id'=>$user->id,'action'=>'admin.bootstrapped','entity_type'=>'user','entity_id'=>$user->id]);
    });
    $this->info('Administrador criado. Cadastre as franquias, os acessos e as condições aprovadas antes da operação.');
    return 0;
})->purpose('Criar o primeiro administrador sem contas demonstrativas nem senhas em argumentos');

Artisan::command('commissions:sync {--demo-rates : Em demonstração, cria percentuais de exemplo para produtos sem percentual}',function() {
    if($this->option('demo-rates')) {
        if(! config('plans.demo') || app()->environment('production')) { $this->error('Percentuais de exemplo exigem APP_DEMO=true fora de produção.'); return 1; }
        $admin=\App\Models\User::where('role','central_admin')->orderBy('id')->first();
        foreach(\App\Models\Product::whereDoesntHave('commissionRates')->get() as $product) {
            \App\Models\CommissionRate::create(['product_id'=>$product->id,'rate_basis_points'=>$product->category==='package'?500:1000,'effective_from'=>now()->subYear(),
                'is_demo'=>true,'note'=>'Valor de exemplo: definir o percentual real.','created_by'=>$admin?->id]);
        }
    }
    $created=0;
    \App\Models\Invoice::where('is_initial',true)->where('status','paid')->chunkById(100,function($invoices)use(&$created) {
        foreach($invoices as $invoice) {
            \Illuminate\Support\Facades\DB::transaction(function()use($invoice,&$created) {
                $existed=\App\Models\Commission::where('subscription_id',$invoice->subscription_id)->exists();
                app(\App\Services\Domain\CommissionService::class)->syncForInvoice($invoice->fresh());
                if(! $existed && \App\Models\Commission::where('subscription_id',$invoice->subscription_id)->exists()) $created++;
            });
        }
    });
    $this->info("{$created} comissão(ões) gerada(s) a partir de primeiros pagamentos já confirmados.");
    return 0;
})->purpose('Gerar comissões de primeiros pagamentos já confirmados, sem duplicar');
