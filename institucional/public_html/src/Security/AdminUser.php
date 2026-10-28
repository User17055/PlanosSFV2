<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private array $record, private array $roles) {}
    public function getUserIdentifier(): string { return (string) $this->record['username']; }
    public function getPassword(): ?string { return $this->record['password']; }
    public function setPassword(string $hash): void { $this->record['password'] = $hash; }
    public function getRoles(): array { return array_values(array_unique(array_merge(['ROLE_USER'], $this->roles))); }
    public function getRecord(): array { return $this->record; }
    public function isEnabled(): bool { return (bool) $this->record['enabled']; }
    public function eraseCredentials(): void {}
    public function __serialize(): array
    {
        $record = $this->record;
        $record['password'] = hash('crc32c', $record['password']);
        return [$record, $this->roles];
    }
    public function __unserialize(array $data): void { [$this->record, $this->roles] = $data; }
}
