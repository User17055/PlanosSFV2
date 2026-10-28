<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Infrastructure;

use Doctrine\DBAL\Connection;

/** Nomes legados isolados nesta ponte; consultas usam o DBAL atual. */
class Database extends Connection
{
    public function fetchAssoc(string $sql, array $params = [], array $types = []): array|false { return $this->fetchAssociative($sql, $params, $types); }
    public function fetchAll(string $sql, array $params = [], array $types = []): array { return $this->fetchAllAssociative($sql, $params, $types); }
    public function fetchArray(string $sql, array $params = [], array $types = []): array|false { return $this->fetchNumeric($sql, $params, $types); }
    public function fetchColumn(string $sql, array $params = [], int $column = 0, array $types = []): mixed
    {
        $row = $this->executeQuery($sql, $params, $types)->fetchNumeric();
        return $row === false ? false : ($row[$column] ?? false);
    }
    public function executeUpdate(string $sql, array $params = [], array $types = []): int|string { return $this->executeStatement($sql, $params, $types); }
}
