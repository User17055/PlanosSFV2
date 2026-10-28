<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Command;

use LuzPropria\LuzPanel\Infrastructure\Database;
use LuzPropria\LuzPanel\Security\AdminUser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'user:new', description: 'Cria um usuário do painel sem expor a senha no terminal.')]
final class UserCreateCommand extends Command
{
    public function __construct(private Database $db, private UserPasswordHasherInterface $hasher) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED)->addArgument('email', InputArgument::REQUIRED)->addArgument('name', InputArgument::REQUIRED)->addOption('role', null, InputOption::VALUE_REQUIRED, 'Papel já cadastrado no banco', 'ROLE_ADMIN');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) { $io->error('Use o modo interativo para informar a senha.'); return Command::INVALID; }
        $username = trim($input->getArgument('username'));
        $email = trim($input->getArgument('email'));
        if (strlen($username) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $io->error('Informe usuário com pelo menos oito caracteres e e-mail válido.'); return Command::INVALID; }
        $password = $io->askHidden('Senha (mínimo de 12 caracteres)', static function (?string $value): string { if (strlen($value ?? '') < 12) { throw new \RuntimeException('A senha precisa ter pelo menos 12 caracteres.'); } return $value; });
        $role = $this->db->fetchAssoc('SELECT id FROM roles WHERE role = ?', [$input->getOption('role')]);
        if (!$role) { $io->error('Papel não encontrado no banco.'); return Command::INVALID; }
        $user = new AdminUser(['username' => $username, 'password' => '', 'enabled' => 1], []);
        $hash = $this->hasher->hashPassword($user, $password);
        $this->db->transactional(function () use ($input, $username, $email, $hash, $role): void {
            $now = date('Y-m-d H:i:s');
            $this->db->insert('users', ['username' => $username, 'email' => $email, 'name' => $input->getArgument('name'), 'password' => $hash, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->db->insert('users_roles', ['user' => $this->db->lastInsertId(), 'role' => $role['id']]);
        });
        $io->success('Usuário criado.');
        return Command::SUCCESS;
    }
}
