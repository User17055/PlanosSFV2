<?php
declare(strict_types=1);

namespace LuzPropria\LuzPanel;

use LuzPropria\LuzPanel\Infrastructure\AppContext;
use LuzPropria\LuzPanel\Infrastructure\Database;
use LuzPropria\LuzPanel\Infrastructure\DatabaseFactory;
use LuzPropria\LuzPanel\Infrastructure\RequestSubscriber;
use LuzPropria\LuzPanel\Security\AdminUser;
use LuzPropria\LuzPanel\Security\AdminUserProvider;
use LuzPropria\LuzPanel\Security\AdminUserChecker;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string { return dirname(__DIR__); }
    public function getCacheDir(): string { return dirname($this->getProjectDir()).'/var_runtime/cache/'.$this->environment; }
    public function getLogDir(): string { return dirname($this->getProjectDir()).'/var_runtime/logs'; }

    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Symfony\Bundle\SecurityBundle\SecurityBundle();
        yield new \Symfony\Bundle\TwigBundle\TwigBundle();
        yield new \Symfony\Bundle\MonologBundle\MonologBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $private = dirname($this->getProjectDir()).'/configuracao_privada/app.php';
        $settings = is_file($private) ? require $private : [];
        $secret = getenv('APP_SECRET') ?: ($settings['secret'] ?? '');
        if (strlen($secret) < 32) { throw new \RuntimeException('Configure APP_SECRET com pelo menos 32 caracteres.'); }

        $framework = [
            'secret' => $secret,
            'http_method_override' => true,
            'router' => ['utf8' => true],
            'default_locale' => 'pt_BR',
            'translator' => ['fallbacks' => ['pt_BR']],
            'form' => ['enabled' => true],
            'csrf_protection' => true,
            'validation' => ['enabled' => true],
            'session' => ['cookie_secure' => 'auto', 'cookie_httponly' => true, 'cookie_samesite' => 'lax', 'storage_factory_id' => $this->environment === 'test' ? 'session.storage.factory.mock_file' : 'session.storage.factory.native', 'save_path' => dirname($this->getProjectDir()).'/var_runtime/sessions'],
            'mailer' => ['dsn' => getenv('MAILER_DSN') ?: 'null://null'],
            'php_errors' => ['log' => true],
        ];
        if ($this->environment === 'test') { $framework['test'] = true; }
        $container->extension('framework', $framework);
        $container->extension('twig', [
            'default_path' => $this->getProjectDir().'/views',
            'strict_variables' => $this->debug,
            'form_themes' => ['form_div_layout.html.twig'],
            'globals' => ['site' => '@'.AppContext::class],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'stream', 'path' => '%kernel.logs_dir%/%kernel.environment%.log', 'level' => $this->debug ? 'debug' : 'error']]]);
        $container->extension('security', [
            'password_hashers' => [
                'legacy_sha512' => ['algorithm' => 'sha512', 'encode_as_base64' => true, 'iterations' => 5000],
                AdminUser::class => ['algorithm' => 'auto', 'migrate_from' => ['legacy_sha512']],
            ],
            'providers' => ['admins' => ['id' => AdminUserProvider::class]],
            'firewalls' => ['main' => [
                'lazy' => true,
                'provider' => 'admins',
                'user_checker' => AdminUserChecker::class,
                'form_login' => ['login_path' => 'luzsisadm_login', 'check_path' => 'luzsisadm_login_check', 'enable_csrf' => true, 'default_target_path' => 's_dashboard', 'always_use_default_target_path' => true],
                'login_throttling' => ['max_attempts' => 5],
                'logout' => ['path' => 'luzsisadm_logout', 'target' => 'luzsisadm_login', 'enable_csrf' => true],
            ]],
            'access_control' => [
                ['path' => '^/luzsisadm/login$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/luzsisadm', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ],
        ]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(DatabaseFactory::class);
        $services->set(Database::class)->factory([service(DatabaseFactory::class), 'create'])->lazy();
        $services->set(\LuzPropria\LuzPanel\Portal\PortalApi::class);
        $services->set(AppContext::class);
        $services->set(RequestSubscriber::class);
        $services->set(AdminUserProvider::class);
        $services->set(AdminUserChecker::class);
        $services->load('LuzPropria\\LuzPanel\\Command\\', $this->getProjectDir().'/src/Command/');
        $services->load('LuzPropria\\LuzPanel\\Controller\\', $this->getProjectDir().'/src/Controller/')->tag('controller.service_arguments');
        $services->load('LuzPropria\\LuzPanel\\Form\\', $this->getProjectDir().'/src/Form/');
        $services->load('LuzPropria\\LuzPanel\\Twig\\', $this->getProjectDir().'/src/Twig/')->exclude([$this->getProjectDir().'/src/Twig/SecurityTwigFunction.php']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir().'/config/routes.php');
        $routes->import($this->getProjectDir().'/config/portal_routes.php');
    }
}
