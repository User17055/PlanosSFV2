<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Security;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
final class AdminUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void { if ($user instanceof AdminUser && !$user->isEnabled()) { throw new DisabledException('Conta desabilitada.'); } }
    public function checkPostAuth(UserInterface $user): void {}
}
