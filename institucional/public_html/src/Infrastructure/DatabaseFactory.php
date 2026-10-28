<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Infrastructure;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class DatabaseFactory
{
    public function create(): Database
    {
        $url = getenv('DATABASE_URL');
        if ($url) {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse($url);
        } else {
            $path = dirname(__DIR__, 3).'/configuracao_privada/database.php';
            if (!is_file($path)) { throw new \RuntimeException('Configure DATABASE_URL ou configuracao_privada/database.php.'); }
            $params = require $path;
        }
        $params['wrapperClass'] = Database::class;
        return DriverManager::getConnection($params);
    }
}
