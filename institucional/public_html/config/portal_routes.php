<?php
declare(strict_types=1);
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function(RoutingConfigurator $routes): void {
    $controller='LuzPropria\\LuzPanel\\Controller\\Front\\PortalController::handle';
    $paths=[
        'home'=>['/area-do-cliente','home',['GET']],
        'login'=>['/area-do-cliente/entrar','login',['GET','POST']],
        'signup'=>['/area-do-cliente/cadastro','signup',['GET','POST']],
        'subscribe'=>['/contratar/{code}','subscribe',['GET','POST']],
        'logout'=>['/area-do-cliente/sair','logout',['POST']],
        'forgot'=>['/area-do-cliente/esqueci-senha','forgot',['GET','POST']],
        'reset'=>['/area-do-cliente/redefinir-senha','reset',['GET','POST']],
        'verify'=>['/area-do-cliente/verificar-email/{user}/{hash}','verify',['GET']],
        'resend'=>['/area-do-cliente/reenviar-email','resend',['POST']],
        'pet'=>['/area-do-cliente/pets/novo','pet',['GET','POST']],
        'postal'=>['/area-do-cliente/cep/{zip}','postal',['GET']],
        'check'=>['/area-do-cliente/verificar-cadastro','check',['POST']],
        'resendmail'=>['/area-do-cliente/reenviar-confirmacao','resendmail',['POST']],
        'confirmed'=>['/area-do-cliente/email-confirmado','confirmed',['GET']],
        'proposal'=>['/area-do-cliente/contratacoes/{id}','proposal',['GET']],
        'review'=>['/area-do-cliente/contratacoes/{id}/conferencia.pdf','review',['GET']],
        'accept'=>['/area-do-cliente/contratacoes/{id}/assinar','accept',['POST']],
        'generate'=>['/area-do-cliente/contratacoes/{id}/gerar-contrato','generate',['POST']],
        'document'=>['/area-do-cliente/documentos/{id}','document',['GET']],
        'checkout'=>['/area-do-cliente/assinaturas/{id}/pagar','checkout',['POST']],
        'invoice'=>['/area-do-cliente/faturas/{id}/pagar','invoice',['POST']],
        'cancel'=>['/area-do-cliente/assinaturas/{id}/cancelamento','cancel',['POST']],
    ];
    foreach($paths as $name=>[$path,$action,$methods]) {
        $routes->add('portal_'.$name,$path)->controller($controller)->defaults(['portal_action'=>$action])
            ->requirements(['id'=>'[1-9][0-9]*','user'=>'[1-9][0-9]*','hash'=>'[a-f0-9]{40}','code'=>'[A-Za-z0-9_-]+','zip'=>'[0-9]{8}'])->methods($methods);
    }
};
