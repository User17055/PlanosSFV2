<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Infrastructure;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

final class AppContext implements \ArrayAccess, ServiceSubscriberInterface
{
    public function __construct(private ContainerInterface $container) {}
    public static function getSubscribedServices(): array
    {
        return ['db' => Database::class, 'twig' => Environment::class, 'request_stack' => RequestStack::class, 'form.factory' => FormFactoryInterface::class, 'url_generator' => UrlGeneratorInterface::class, 'security' => AuthorizationCheckerInterface::class, 'token_storage' => TokenStorageInterface::class];
    }
    public function offsetGet(mixed $key): mixed
    {
        return match ($key) {
            'request' => $this->container->get('request_stack')->getCurrentRequest(),
            'session' => $this['request']?->getSession(),
            'security_path' => 'luzsisadm',
            'composer' => $this->metadata(),
            'user' => $this->container->get('token_storage')->getToken()?->getUser(),
            'user_security' => ($this['user'] instanceof \LuzPropria\LuzPanel\Security\AdminUser) ? $this['user']->getRecord() : [],
            'get_seo' => $this->seo(),
            default => $this->container->get((string) $key),
        };
    }
    private function metadata(): array
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['version'] = $data['extra']['site_version'] ?? '1.0.0';
        return $data;
    }
    private function seo(): array
    {
        $request = $this['request'];
        if (!$request) { return []; }
        if (!$request->attributes->has('_site_seo')) {
            $request->attributes->set('_site_seo', $this['db']->fetchAssoc('SELECT * FROM `seo` WHERE ? REGEXP `url` AND `enabled` = 1 ORDER BY `order` ASC', [$request->getPathInfo()]) ?: []);
        }
        return $request->attributes->get('_site_seo');
    }
    public function offsetExists(mixed $key): bool { return in_array($key, ['request','session','security_path','composer','user','user_security','get_seo'], true) || $this->container->has((string) $key); }
    public function offsetSet(mixed $key, mixed $value): void { throw new \LogicException('Contexto somente leitura.'); }
    public function offsetUnset(mixed $key): void { throw new \LogicException('Contexto somente leitura.'); }
    public function json(mixed $data, int $status = 200, array $headers = []): \Symfony\Component\HttpFoundation\JsonResponse { return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers); }
    public function redirect(string $url): \Symfony\Component\HttpFoundation\RedirectResponse { return new \Symfony\Component\HttpFoundation\RedirectResponse($url); }
    public function stream(callable $callback, int $status = 200, array $headers = []): \Symfony\Component\HttpFoundation\StreamedResponse { return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, $status, $headers); }
}
