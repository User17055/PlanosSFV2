<?php

use App\Http\Controllers\{AuthController, CatalogController, ContractController, CustomerController, DashboardController, FinanceController, IntegrationController, PortalController, ProposalController, RegistrationController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/',fn(Request $r)=>$r->user()?redirect()->route(['customer'=>'portal','attendant'=>'leads.index'][$r->user()->role] ?? 'dashboard'):redirect()->route('login'))->name('home');
Route::get('/planos',fn()=>redirect()->away(config('site_portal.public_url').'/planos-saude/'))->middleware(\App\Http\Middleware\CaptureAcquisition::class)->name('sales');
Route::get('/contratar/{code}',[CatalogController::class,'subscribe'])->middleware(\App\Http\Middleware\CaptureAcquisition::class)->name('sales.subscribe');
Route::get('/enderecos/cep/{zip}',\App\Http\Controllers\PostalAddressController::class)->middleware('throttle:30,1')->name('addresses.postal');
Route::get('/planos/{version}/contrato',[CatalogController::class,'offeredContract'])->middleware('throttle:30,1')->name('sales.contract');
Route::get('/l/{token}',[\App\Http\Controllers\MarketingLinkController::class,'visit'])->middleware('throttle:60,1')->name('attribution_links.visit');
// O link de assinatura abre sem senha: quem o recebe confirma o CPF e chega só àquele contrato.
Route::get('/convite/{token}',[ProposalController::class,'publicProposal'])->middleware(['active','throttle:30,1'])->name('sales.proposal');
Route::post('/convite/{token}',[ProposalController::class,'confirmLink'])->middleware('throttle:10,1')->name('sales.proposal.confirm');
Route::get('/acesso/confirmado/{user}',[AuthController::class,'confirmedAccess'])->middleware(['signed','throttle:20,1'])->name('login.confirmed');
Route::get('/acesso/site/{user}',[AuthController::class,'siteAccess'])->middleware(['signed','throttle:20,1'])->name('login.site');
Route::middleware('guest')->group(function () {
    Route::get('/entrar',[AuthController::class,'login'])->name('login');
    Route::post('/entrar',[AuthController::class,'authenticate'])->name('authenticate');
    Route::get('/esqueci-senha',[\App\Http\Controllers\PasswordResetController::class,'request'])->name('password.request');
    Route::post('/esqueci-senha',[\App\Http\Controllers\PasswordResetController::class,'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}',[\App\Http\Controllers\PasswordResetController::class,'edit'])->name('password.reset');
    Route::post('/redefinir-senha',[\App\Http\Controllers\PasswordResetController::class,'update'])->middleware('throttle:10,1')->name('password.update');
    Route::get('/cadastro',[RegistrationController::class,'create'])->name('register');
    Route::post('/cadastro',[RegistrationController::class,'store'])->middleware('throttle:5,1')->name('register.store');
});
// A Vindi verifica o endereço ao cadastrar o webhook: responder 200 a uma consulta simples (sem dados) e 202 a um ping sem evento.
Route::match(['get','head'],'/webhooks/vindi',fn()=>response()->json(['ok'=>true]))->middleware('throttle:60,1');
Route::post('/webhooks/vindi',function(Request $request) {
    $request->headers->set('Accept','application/json');
    try { $event=app(\App\Services\Integrations\VindiWebhookService::class)->receive($request); }
    catch (\Illuminate\Validation\ValidationException) { return response()->json(['received'=>false,'ignored'=>'sem evento reconhecível'],202); }
    return response()->json(['received'=>true,'event_id'=>$event->id],202);
})->middleware('throttle:120,1')->name('webhooks.vindi');

Route::post('/webhooks/leads/{token}',[\App\Http\Controllers\LeadController::class,'intake'])->middleware('throttle:120,1')->name('webhooks.leads');

// "Entrar com São Francisco" na Tudo pra Pet: troca do código e dados do cliente (servidor a servidor).
Route::get('/.well-known/openid-configuration',[\App\Http\Controllers\OAuthController::class,'discovery'])->middleware('throttle:60,1')->name('oauth.discovery');
Route::get('/oauth/jwks',[\App\Http\Controllers\OAuthController::class,'jwks'])->middleware('throttle:60,1')->name('oauth.jwks');
Route::post('/oauth/token',[\App\Http\Controllers\OAuthController::class,'token'])->middleware('throttle:30,1')->name('oauth.token');
Route::get('/oauth/usuario',[\App\Http\Controllers\OAuthController::class,'userinfo'])->middleware('throttle:60,1')->name('oauth.userinfo');

Route::middleware(['auth','active'])->group(function () {
    Route::get('/oauth/autorizar',[\App\Http\Controllers\OAuthController::class,'authorize'])->middleware(['role:customer','throttle:30,1'])->name('oauth.authorize');
    Route::post('/oauth/autorizar',[\App\Http\Controllers\OAuthController::class,'approve'])->middleware(['role:customer','throttle:30,1'])->name('oauth.approve');
    Route::middleware('role:central_admin,manager,sales,operator,attendant')->group(function () {
        Route::get('/leads/chat', [\App\Http\Controllers\LeadChatController::class, 'index'])->name('leads.chat.index');
        Route::get('/leads/chat/conexao', [\App\Http\Controllers\DigisacConnectionController::class, 'edit'])->name('leads.chat.connection');
        Route::post('/leads/chat/conexao', [\App\Http\Controllers\DigisacConnectionController::class, 'save'])->middleware('throttle:10,1')->name('leads.chat.connection.save');
        Route::post('/leads/chat/desconectar', [\App\Http\Controllers\DigisacConnectionController::class, 'destroy'])->middleware('throttle:10,1')->name('leads.chat.connection.destroy');
        Route::get('/leads/chat/{lead}', [\App\Http\Controllers\LeadChatController::class, 'index'])->name('leads.chat.show');
        Route::post('/leads/chat/{lead}/assumir', [\App\Http\Controllers\LeadChatController::class, 'claim'])->middleware('throttle:30,1')->name('leads.chat.claim');
        Route::get('/leads/chat/{lead}/foto', [\App\Http\Controllers\LeadChatController::class, 'photo'])->name('leads.chat.photo');
        Route::get('/leads/chat/{lead}/mensagens', [\App\Http\Controllers\LeadChatController::class, 'messages'])->middleware('throttle:60,1')->name('leads.chat.messages');
        Route::post('/leads/chat/{lead}/mensagens', [\App\Http\Controllers\LeadChatController::class, 'send'])->middleware('throttle:20,1')->name('leads.chat.send');
    });
    Route::get('/leads',[\App\Http\Controllers\LeadController::class,'index'])->middleware('role:central_admin,manager,sales,operator,attendant')->name('leads.index');
    Route::get('/leads/painel',[\App\Http\Controllers\LeadController::class,'dashboard'])->middleware('role:central_admin,manager,sales,operator,attendant')->name('leads.dashboard');
    Route::post('/leads/{lead}',[\App\Http\Controllers\LeadController::class,'update'])->middleware(['role:central_admin,manager,sales,operator,attendant','throttle:60,1'])->name('leads.update');
    Route::post('/leads/{lead}/responsavel',[\App\Http\Controllers\LeadController::class,'assign'])->middleware(['role:central_admin,manager','throttle:60,1'])->name('leads.assign');
    Route::post('/leads/{lead}/excluir',[\App\Http\Controllers\LeadController::class,'destroy'])->middleware(['role:central_admin,manager','throttle:30,1'])->name('leads.destroy');
    Route::post('/sair',[AuthController::class,'logout'])->name('logout');
    Route::get('/entrar/escolher',[\App\Http\Controllers\AccountSwitchController::class,'choose'])->middleware('role:central_admin')->name('account.choose');
    Route::post('/entrar/escolher/painel',[\App\Http\Controllers\AccountSwitchController::class,'admin'])->middleware('role:central_admin')->name('account.admin');
    Route::post('/entrar/escolher/cliente',[\App\Http\Controllers\AccountSwitchController::class,'customer'])->middleware(['role:central_admin','throttle:10,1'])->name('account.customer');
    Route::post('/voltar-ao-painel',[\App\Http\Controllers\AccountSwitchController::class,'back'])->middleware(['role:customer','throttle:10,1'])->name('account.back');
    Route::get('/l/{token}/contrato',[\App\Http\Controllers\MarketingLinkController::class,'contractPreview'])->middleware('role:central_admin,manager,sales')->name('attribution_links.contract-preview');
    Route::get('/l/{token}/contrato/pdf',[\App\Http\Controllers\MarketingLinkController::class,'contractPreviewPdf'])->middleware('role:central_admin,manager,sales')->name('attribution_links.contract-preview-pdf');
    Route::get('/verificar-email/{user}/{hash}',[RegistrationController::class,'verify'])->middleware(['signed','throttle:6,1','role:customer'])->name('verification.verify');
    Route::post('/verificar-email',[RegistrationController::class,'resend'])->middleware(['throttle:3,1','role:customer'])->name('verification.resend');
    Route::get('/portal',PortalController::class)->middleware('role:customer')->name('portal');
    Route::get('/meus-pets/{pet}',[PortalController::class,'pet'])->middleware('role:customer')->name('portal.pet');
    Route::get('/meus-pets/{pet}/planos/{subscription}',[PortalController::class,'plan'])->middleware('role:customer')->name('portal.plan');
    Route::get('/faturas',[PortalController::class,'invoices'])->middleware('role:customer')->name('portal.invoices');
    Route::get('/faturas/{invoice}/abrir',[PortalController::class,'openInvoice'])->middleware(['role:customer','throttle:30,1'])->name('portal.invoice.open');
    Route::post('/assinaturas/{subscription}/pagamento',\App\Http\Controllers\CheckoutController::class)->middleware(['role:customer','throttle:6,1'])->name('subscriptions.checkout');
    Route::get('/propostas',[ProposalController::class,'index'])->name('proposals.index');
    Route::get('/propostas/nova',[ProposalController::class,'create'])->middleware('role:central_admin,manager,sales,customer')->name('proposals.create');
    Route::get('/propostas/preenchimento/{link}',[ProposalController::class,'registrationLink'])->middleware('role:central_admin,manager,sales')->name('proposals.registration-link');
    Route::post('/propostas',[ProposalController::class,'store'])->middleware(['role:central_admin,manager,sales,customer','throttle:30,1'])->name('proposals.store');
    Route::get('/propostas/{proposal}',[ProposalController::class,'show'])->name('proposals.show');
    Route::get('/propostas/{proposal}/previa-pdf',[ProposalController::class,'previewPdf'])->middleware('throttle:15,1')->name('proposals.preview-pdf');
    Route::post('/propostas/{proposal}/aceite',[ProposalController::class,'accept'])->middleware(['role:customer','throttle:10,1'])->name('proposals.accept');
    Route::post('/propostas/{proposal}/regerar-contrato',[ProposalController::class,'regenerate'])->middleware(['role:central_admin,manager,sales,customer','throttle:5,1'])->name('proposals.regenerate-contract');
    Route::get('/propostas/{proposal}/contrato',[ContractController::class,'proposal'])->name('proposals.contract');
    Route::get('/propostas/{proposal}/imprimir',[ContractController::class,'proposal'])->middleware('role:central_admin,manager,sales')->name('proposals.paper-download');
    Route::get('/documentos/{document}/download',[ContractController::class,'download'])->middleware('throttle:30,1')->name('contracts.download');
    Route::post('/clientes/{customer}/pets',[CustomerController::class,'pet'])->middleware('role:central_admin,manager,sales,customer')->name('customers.pets.store');
    Route::get('/clientes/{customer}/editar',[CustomerController::class,'edit'])->middleware('role:central_admin,manager,sales,customer')->name('customers.edit');
    Route::post('/clientes/{customer}/editar',[CustomerController::class,'update'])->middleware('role:central_admin,manager,sales,customer')->name('customers.update');
    Route::get('/pets/{pet}/editar',[CustomerController::class,'editPet'])->middleware('role:central_admin,manager,sales,customer')->name('pets.edit');
    Route::post('/pets/{pet}/editar',[CustomerController::class,'updatePet'])->middleware('role:central_admin,manager,sales,customer')->name('pets.update');
    Route::get('/pets/{pet}/foto',[CustomerController::class,'petPhoto'])->middleware('role:central_admin,manager,sales,customer')->name('pets.photo');
    Route::post('/pets/{pet}/foto',[CustomerController::class,'storePetPhoto'])->middleware(['role:central_admin,manager,sales,customer','throttle:10,1'])->name('pets.photo.store');
    Route::post('/assinaturas/{subscription}/solicitar-cancelamento',[ProposalController::class,'requestCancellation'])->middleware(['role:customer','throttle:5,1'])->name('subscriptions.request-cancellation');

    Route::middleware('role:central_admin,manager,sales,operator,finance')->group(function () {
        Route::get('/painel',DashboardController::class)->name('dashboard');
        Route::get('/painel/exportar',[DashboardController::class,'export'])->name('dashboard.export');
        Route::post('/simplesvet/{subscription}/tentar-de-novo',[IntegrationController::class,'retrySimplesVet'])->middleware('throttle:20,1')->name('simplesvet.retry');
        Route::get('/painel/malha/{state}',\App\Http\Controllers\DashboardMapController::class)->name('dashboard.map');
        Route::get('/painel/bairros/{city}',[\App\Http\Controllers\DashboardMapController::class,'neighborhoods'])->name('dashboard.neighborhoods');
        Route::get('/funcionarios',[\App\Http\Controllers\EmployeeController::class,'index'])->middleware('role:central_admin,manager,finance')->name('employees.index');
        Route::get('/funcionarios/novo',[\App\Http\Controllers\EmployeeController::class,'create'])->middleware('role:central_admin')->name('employees.create');
        Route::post('/funcionarios',[\App\Http\Controllers\EmployeeController::class,'store'])->middleware('role:central_admin')->name('employees.store');
        Route::get('/funcionarios/{employee}/editar',[\App\Http\Controllers\EmployeeController::class,'edit'])->middleware('role:central_admin')->name('employees.edit');
        Route::post('/funcionarios/{employee}',[\App\Http\Controllers\EmployeeController::class,'update'])->middleware('role:central_admin')->name('employees.update');
        Route::get('/cargos',[\App\Http\Controllers\JobRoleController::class,'index'])->middleware('role:central_admin')->name('employees.roles.index');
        Route::get('/cargos/novo',[\App\Http\Controllers\JobRoleController::class,'create'])->middleware('role:central_admin')->name('employees.roles.create');
        Route::post('/cargos',[\App\Http\Controllers\JobRoleController::class,'store'])->middleware('role:central_admin')->name('employees.roles.store');
        Route::get('/cargos/{jobRole}/editar',[\App\Http\Controllers\JobRoleController::class,'edit'])->middleware('role:central_admin')->name('employees.roles.edit');
        Route::post('/cargos/{jobRole}',[\App\Http\Controllers\JobRoleController::class,'update'])->middleware('role:central_admin')->name('employees.roles.update');
        Route::post('/cargos/{jobRole}/excluir',[\App\Http\Controllers\JobRoleController::class,'destroy'])->middleware('role:central_admin')->name('employees.roles.destroy');
        Route::get('/clientes',[CustomerController::class,'index'])->name('customers.index');
        Route::get('/clientes/novo',[CustomerController::class,'create'])->middleware('role:central_admin,manager,sales')->name('customers.create');
        Route::post('/clientes',[CustomerController::class,'store'])->middleware('role:central_admin,manager,sales')->name('customers.store');
        Route::get('/clientes/{customer}',[CustomerController::class,'show'])->name('customers.show');
        Route::get('/base-antiga/pets',[\App\Http\Controllers\LegacyPendingController::class,'index'])->middleware('role:central_admin,manager')->name('legacy.pending');
        Route::post('/base-antiga/pets/{item}',[\App\Http\Controllers\LegacyPendingController::class,'link'])->middleware(['role:central_admin,manager','throttle:60,1'])->name('legacy.pending.link');
        Route::post('/base-antiga/pets/{item}/dispensar',[\App\Http\Controllers\LegacyPendingController::class,'dismiss'])->middleware(['role:central_admin,manager','throttle:30,1'])->name('legacy.pending.dismiss');
        Route::get('/catalogo',[CatalogController::class,'index'])->name('catalog.index');
        Route::get('/catalogo/novo',[CatalogController::class,'create'])->middleware('role:central_admin')->name('catalog.create');
        Route::get('/catalogo/{version}/modelo',[CatalogController::class,'previewTemplate'])->middleware('role:central_admin')->name('catalog.template-preview');
        Route::get('/catalogo/{version}/modelo/pdf',[CatalogController::class,'templatePdf'])->middleware('role:central_admin')->name('catalog.template-pdf');
        Route::post('/catalogo',[CatalogController::class,'store'])->middleware('role:central_admin')->name('catalog.store');
        Route::post('/catalogo/{version}/publicar',[CatalogController::class,'publish'])->middleware('role:central_admin')->name('catalog.publish');
        Route::post('/catalogo/{version}/nova-versao',[CatalogController::class,'cloneVersion'])->middleware('role:central_admin')->name('catalog.clone');
        Route::get('/catalogo/{version}/editar',[CatalogController::class,'edit'])->middleware('role:central_admin')->name('catalog.edit');
        Route::post('/catalogo/{version}/editar',[CatalogController::class,'updateVersion'])->middleware('role:central_admin')->name('catalog.update');
        Route::get('/campanhas',[\App\Http\Controllers\CampaignController::class,'index'])->middleware('role:central_admin')->name('campaigns.index');
        Route::post('/campanhas',[\App\Http\Controllers\CampaignController::class,'store'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.store');
        Route::post('/campanhas/{campaign}',[\App\Http\Controllers\CampaignController::class,'update'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.update');
        Route::post('/campanhas/{campaign}/principal',[\App\Http\Controllers\CampaignController::class,'principal'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.principal');
        Route::post('/campanhas/{campaign}/tirar-principal',[\App\Http\Controllers\CampaignController::class,'notPrincipal'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.not-principal');
        Route::post('/campanhas/{campaign}/arquivar',[\App\Http\Controllers\CampaignController::class,'archive'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.archive');
        Route::post('/campanhas/{campaign}/reativar',[\App\Http\Controllers\CampaignController::class,'restore'])->middleware(['role:central_admin','throttle:20,1'])->name('campaigns.restore');
        Route::post('/propostas/{proposal}/link',[ProposalController::class,'link'])->middleware('role:central_admin,manager,sales')->name('proposals.link');
        Route::post('/propostas/{proposal}/papel',[ProposalController::class,'paper'])->middleware('role:central_admin,manager,sales')->name('proposals.issue-paper');
        Route::post('/propostas/{proposal}/tablet',[ProposalController::class,'tablet'])->middleware('role:central_admin,manager,sales')->name('proposals.tablet-sign');
        Route::get('/propostas/{proposal}/foto-do-titular',[ProposalController::class,'selfie'])->middleware('role:central_admin,manager,sales')->name('proposals.selfie');
        Route::post('/propostas/{proposal}/documentos',[ContractController::class,'upload'])->middleware('role:central_admin,manager,sales')->name('proposals.documents.store');
        Route::post('/propostas/{proposal}/cancelar',[ProposalController::class,'cancel'])->middleware('role:central_admin,manager,sales')->name('proposals.cancel');
        Route::post('/documentos/{document}/validar',[ContractController::class,'validateDocument'])->middleware('role:central_admin,manager')->name('contracts.validate');
        Route::post('/assinaturas/{subscription}/cancelar',[ProposalController::class,'cancelSubscription'])->middleware('role:central_admin,manager')->name('subscriptions.cancel');
        Route::get('/financeiro',[FinanceController::class,'index'])->middleware('role:central_admin,manager,finance')->name('finance.index');
        Route::post('/financeiro/{invoice}/confirmar',[FinanceController::class,'confirm'])->middleware('role:central_admin,finance')->name('finance.confirm');
        Route::get('/comissoes',[\App\Http\Controllers\CommissionController::class,'index'])->middleware('role:central_admin,manager,finance,sales')->name('commissions.index');
        Route::post('/comissoes/percentuais',[\App\Http\Controllers\CommissionController::class,'rates'])->middleware('role:central_admin')->name('commissions.rates');
        Route::post('/comissoes/{commission}/pagar',[\App\Http\Controllers\CommissionController::class,'pay'])->middleware(['role:central_admin,finance','throttle:30,1'])->name('commissions.pay');
        Route::get('/integracoes',[IntegrationController::class,'index'])->middleware('role:central_admin')->name('integrations.index');
        Route::post('/integracoes/{event}/reprocessar',[IntegrationController::class,'retry'])->middleware('role:central_admin')->name('integrations.retry');
        Route::get('/rede',[\App\Http\Controllers\ManagementController::class,'index'])->middleware('role:central_admin')->name('management.index');
        Route::get('/relatorios',[\App\Http\Controllers\ReportController::class,'index'])->middleware('role:central_admin,manager,finance')->name('reports.index');
        Route::get('/relatorios/exportar',[\App\Http\Controllers\ReportController::class,'export'])->middleware('role:central_admin,manager,finance')->name('reports.export');
        Route::get('/links-divulgacao',[\App\Http\Controllers\MarketingLinkController::class,'index'])->middleware('role:central_admin,manager,sales')->name('attribution_links.index');
        Route::post('/links-divulgacao',[\App\Http\Controllers\MarketingLinkController::class,'store'])->middleware('role:central_admin,manager,sales')->name('attribution_links.store');
        Route::post('/links-divulgacao/{link}/desativar',[\App\Http\Controllers\MarketingLinkController::class,'revoke'])->middleware('role:central_admin,manager,sales')->name('attribution_links.revoke');
        Route::post('/rede/franquias',[\App\Http\Controllers\ManagementController::class,'franchise'])->middleware('role:central_admin')->name('management.franchises.store');
        Route::post('/rede/usuarios',[\App\Http\Controllers\ManagementController::class,'user'])->middleware('role:central_admin')->name('management.users.store');
        Route::post('/rede/usuarios/{user}',[\App\Http\Controllers\ManagementController::class,'updateUser'])->middleware('role:central_admin')->name('management.users.update');
    });
});
