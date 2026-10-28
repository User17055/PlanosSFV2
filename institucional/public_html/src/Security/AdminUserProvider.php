<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Security;

use LuzPropria\LuzPanel\Infrastructure\Database;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

final class AdminUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private Database $db) {}
    public function loadUserByIdentifier(string $identifier): AdminUser
    {
        $record = $this->db->fetchAssoc('SELECT * FROM `users` WHERE `username` = ? AND `deleted_at` IS NULL', [trim($identifier)]);
        if (!$record || !(bool) $record['enabled']) { $e = new UserNotFoundException(); $e->setUserIdentifier($identifier); throw $e; }
        $roles = $this->db->fetchAll('SELECT R.role FROM users_roles UR JOIN roles R ON UR.role = R.id WHERE UR.user = ?', [$record['id']]);
        return new AdminUser($record, array_column($roles, 'role'));
    }
    public function refreshUser(UserInterface $user): AdminUser
    {
        if (!$user instanceof AdminUser) { throw new UnsupportedUserException(); }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }
    public function supportsClass(string $class): bool { return $class === AdminUser::class; }
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AdminUser) { throw new UnsupportedUserException(); }
        $this->db->executeStatement('UPDATE users SET password = ? WHERE id = ?', [$newHashedPassword, $user->getRecord()['id']]);
        $user->setPassword($newHashedPassword);
    }
}
