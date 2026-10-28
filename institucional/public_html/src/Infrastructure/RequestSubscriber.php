<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RequestSubscriber implements EventSubscriberInterface
{
    public function __construct(private Database $db, private AuthorizationCheckerInterface $authorization, private CsrfTokenManagerInterface $csrf) {}
    public static function getSubscribedEvents(): array { return ['kernel.request' => ['checkAccess', -10], 'kernel.controller' => 'convertArguments', 'kernel.view' => 'response']; }
    public function checkAccess(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        // Customer routes authenticate against the management API, independently of CMS roles.
        if (str_starts_with((string)$request->attributes->get('_route'),'portal_')) { return; }
        if ($request->attributes->get('_route')==='web_planos' && getenv('APP_ENV')==='local' && getenv('PORTAL_LOCAL_PREVIEW')==='1') { return; }
        if (!$request->attributes->has('_route') || str_starts_with((string) $request->attributes->get('_route'), 'luzsisadm_')) { return; }
        if (str_starts_with($request->getPathInfo(), '/luzsisadm') && !$request->isMethodSafe()) {
            $token = $request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_csrf_token');
            $body = $request->request->all();
            $hasForm = preg_match('/_(?:create|edit)$/', (string) $request->attributes->get('_route')) === 1 && count($body) === 1 && is_array(current($body));
            if (!$hasForm && !$this->csrf->isTokenValid(new CsrfToken('admin_action', (string) $token))) { throw new AccessDeniedHttpException('Token de segurança inválido.'); }
        }
        $rules = $this->db->fetchAll('SELECT `path`, `roles`, `methods`, `host`, `ip` FROM `roles_access` ORDER BY `order` DESC');
        foreach ($rules as $rule) {
            if (@preg_match('#'.str_replace('#', '\\#', $rule['path']).'#', $request->getPathInfo()) !== 1) { continue; }
            $methods = json_decode($rule['methods'] ?? 'null', true) ?: [];
            if ($methods && !in_array($request->getMethod(), $methods, true)) { continue; }
            if (!empty($rule['host']) && @preg_match('#'.$rule['host'].'#i', $request->getHost()) !== 1) { continue; }
            if (!empty($rule['ip']) && !IpUtils::checkIp($request->getClientIp() ?? '', array_map('trim', explode(',', $rule['ip'])))) { continue; }
            $roles = json_decode($rule['roles'] ?? '[]', true) ?: [];
            foreach ($roles as $role) {
                if (in_array($role, ['IS_AUTHENTICATED_ANONYMOUSLY','PUBLIC_ACCESS'], true) || $this->authorization->isGranted($role)) { return; }
            }
            if ($roles) { throw new AccessDeniedHttpException('Acesso negado.'); }
            return;
        }
    }
    public function convertArguments(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        foreach (['banner_type' => 'banner_type', 'institutional_type' => 'institutional_type'] as $name => $table) {
            if ($request->attributes->has($name) && !is_array($request->attributes->get($name))) {
                $row = $this->db->fetchAssoc('SELECT * FROM `'.$table.'` WHERE id = ?', [$request->attributes->get($name)]);
                if (!$row) { throw new NotFoundHttpException(); }
                $request->attributes->set($name, $row);
            }
        }
    }
    public function response(ViewEvent $event): void
    {
        $result = $event->getControllerResult();
        $event->setResponse(is_array($result) || is_bool($result) ? new JsonResponse($result) : new Response((string) $result));
    }
}
