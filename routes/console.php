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

Artisan::command('plans:install-health-contracts {plano? : Instala só este plano, por exemplo economico-felino}', function () {
    $installer=app(\App\Services\Contracts\HealthPlanInstaller::class);
    $slug=$this->argument('plano');
    if ($slug && !isset(\App\Services\Contracts\HealthPlanInstaller::PLANS[$slug])) return $this->error('Plano desconhecido: '.$slug);
    foreach($slug?[$installer->installOne($slug)]:$installer->installAll() as $version) {
        $this->info($version->name.' · R$ '.number_format($version->price_cents/100,2,',','.').' · versão '.$version->id.' · '.$version->status);
    }
})->purpose('Instalar os planos reais e seus contratos originais com campos automáticos');

Artisan::command('plans:install-economic-contract', function () {
    $version = app(\App\Services\Contracts\EconomicContractInstaller::class)->install();
    $this->info('Modelo Econômico instalado no catálogo: versão '.$version->id.'.');
    $this->info('O rascunho conserva os valores e benefícios do documento. Descontos dependem de implementação antes da publicação.');
})->purpose('Instalar o contrato Econômico com campos de preenchimento, sem alterar contratos existentes');

Artisan::command('plans:sync-tudoprapet {--cliente= : Só este cliente (id)} {--forcar : Reenvia mesmo sem mudança}', function () {
    if (! app(\App\Services\Integrations\TudoPraPetClient::class)->enabled()) return $this->warn('Integração com a Tudo pra Pet desligada (falta TUDOPRAPET_ENABLED, TUDOPRAPET_URL ou TUDOPRAPET_API_KEY).');
    $customers=\App\Models\Customer::query()->when($this->option('cliente'),fn($q,$id)=>$q->whereKey($id),fn($q)=>$q->where(fn($w)=>$w->where('tudoprapet_status','active')
        ->orWhereHas('subscriptions',fn($s)=>$s->where('category','health_plan')->whereNotNull('activated_at')->whereNotIn('status',\App\Models\Subscription::TERMINAL))))->pluck('id');
    foreach($customers as $id) \App\Jobs\SyncTudoPraPetJob::dispatch((int)$id,(bool)$this->option('forcar'));
    $this->info('Conferência da Tudo pra Pet enviada para a fila: '.$customers->count().' cliente(s).');
})->purpose('Coloca ou tira os tutores do grupo "Plano São Francisco" da Tudo pra Pet conforme os planos e o atraso');

Artisan::command('plans:sync-simplesvet {--contrato= : Roda agora só este contrato (id)} {--ensaio : Percorre e preenche no SimplesVet sem salvar}', function (\App\Services\Integrations\SimplesVetAdapter $simplesvet) {
    if (! $simplesvet->enabled()) return $this->warn('Robô do SimplesVet desligado (falta SIMPLESVET_ENABLED, SIMPLESVET_USER ou SIMPLESVET_PASSWORD).');
    if ($id = $this->option('contrato')) {
        $result = $simplesvet->synchronize(\App\Models\Subscription::findOrFail((int) $id), (bool) $this->option('ensaio'));
        foreach ($result['actions'] ?? [] as $action) $this->line('· '.$action);
        return $this->info(($this->option('ensaio') ? 'Ensaio (nada salvo). ' : '').'SimplesVet: tutor '.($result['tutor_id'] ?? '-').', pet '.($result['pet_id'] ?? '-').'.');
    }
    // Conferência: contratos ativos cujo pet ainda não foi ligado à ficha do SimplesVet.
    $ids = \App\Models\Subscription::where('status', 'active')->whereHas('pet', fn ($q) => $q->whereNull('simplesvet_id'))->pluck('id');
    foreach ($ids as $id) \App\Jobs\SyncSimplesVetJob::dispatch((int) $id);
    $this->info('Contratos enviados ao robô do SimplesVet: '.$ids->count().'.');
})->purpose('Leva os planos ao SimplesVet: CLIENTE PLANO no tutor e o plano com a data no pet');

Artisan::command('plans:tudoprapet-teste {acao : status, activate ou deactivate} {cpf} {--nome=} {--email=} {--telefone=}', function () {
    // Só para a conta de teste combinada com a Tudo pra Pet; não grava nada aqui.
    $data=['cpf'=>preg_replace('/\D/','',(string)$this->argument('cpf'))];
    if ($this->argument('acao')==='activate') $data+=['name'=>(string)$this->option('nome'),'email'=>(string)$this->option('email'),'phone'=>preg_replace('/\D/','',(string)$this->option('telefone'))];
    try { $result=app(\App\Services\Integrations\TudoPraPetClient::class)->call((string)$this->argument('acao'),$data); }
    catch(\App\Services\Integrations\TudoPraPetException $e) { return $this->error($e->getMessage().($e->retryable?' (temporário)':'')); }
    $this->info(json_encode(\Illuminate\Support\Arr::except($result,['user_id']),JSON_UNESCAPED_UNICODE));
})->purpose('Testa a API da Tudo pra Pet com a conta de teste');

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

Artisan::command('plans:import-vindi-customers {--apply : Grava os cadastros; sem esta opção, só mostra o que seria feito}',function(\App\Services\Integrations\VindiCustomerImport $import) {
    $apply=(bool)$this->option('apply');
    $report=$import->run($apply);
    $this->table(['Plano','Assinaturas ativas'],collect($report['plans'])->map(fn($total,$name)=>[$name,$total])->values()->all());
    $this->line('Fora da importação: '.implode(', ',$report['excluded']));
    $this->line('Clientes com plano ativo: '.$report['found']);
    $this->info(($apply?'Criados: ':'Seriam criados: ').$report['created']." (com e-mail: {$report['filled']['email']}, com telefone: {$report['filled']['phone']}, com endereço: {$report['filled']['address']})");
    $this->info(($apply?'Ligados a um cadastro existente: ':'Seriam ligados a um cadastro existente: ').$report['linked']);
    $this->line('Já estavam importados: '.$report['existing']);
    // Só os ids da Vindi: nome e CPF não vão para o terminal.
    foreach($report['skipped'] as $reason=>$ids) if($ids) $this->warn($reason.' ('.count($ids).'): '.implode(', ',$ids));
    if(!$apply) $this->comment('Nada foi gravado. Para gravar, rode de novo com --apply.');
})->purpose('Trazer da Vindi o cadastro de quem tem plano ativo, sem pet, contrato ou assinatura');

Schedule::command('plans:recover-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('plans:reconcile')->everyFifteenMinutes()->withoutOverlapping();
// 03:10 de Brasília: pega quem passou do último dia do mês sem pagar e quem teve o plano encerrado.
Schedule::command('plans:sync-tudoprapet')->dailyAt('06:10')->withoutOverlapping();
// 03:40 de Brasília: o que não chegou ao SimplesVet de dia (robô fora, aviso resolvido pela recepção) entra de novo.
Schedule::command('plans:sync-simplesvet')->dailyAt('06:40')->withoutOverlapping();
Artisan::command('leads:digisac-photos',function(\App\Services\Integrations\LeadPhotoSync $photos) {
    $this->info($photos->syncPending().' leads conferidos.');
})->purpose('Guardar a foto do contato no Digisac dos leads com telefone, sem precisar abrir a conversa');
Schedule::command('leads:digisac-photos')->everyThirtyMinutes()->withoutOverlapping();
Artisan::command('leads:digisac-assign',function(\App\Services\Integrations\LeadDigisacAssign $assign) {
    $this->info($assign->run().' lead(s) marcados com quem puxou no Digisac.');
})->purpose('Lead da fila fica com quem puxou a conversa no Digisac');
Schedule::command('leads:digisac-assign')->everyTwoMinutes()->withoutOverlapping();
Artisan::command('proposals:payment-reminders {--ensaio : Só lista quem receberia}',function(\App\Services\Domain\PaymentReminders $reminders) {
    $due=$reminders->due();
    foreach($due as $p) $this->line('· proposta '.$p->id.' (assinada em '.$p->accepted_at->timezone('America/Sao_Paulo')->format('d/m').')');
    if($this->option('ensaio')) return $this->info('Ensaio: '.$due->count().' lembrete(s) seriam enviados.');
    $this->info($due->filter(fn($p)=>$reminders->send($p))->count().' lembrete(s) de pagamento enviados.');
})->purpose('Lembrar por e-mail quem assinou e não pagou a entrada');
// 10h de Brasília: até 3 lembretes, com 2 dias entre eles.
Schedule::command('proposals:payment-reminders')->dailyAt('13:00')->withoutOverlapping();
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
