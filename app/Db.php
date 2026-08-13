<?php
declare(strict_types=1);

namespace Civi;

use PDO;
use PDOStatement;

/** Тонкая обёртка над PDO: подключение, короткие хелперы, транзакции. */
final class Db
{
    /** @var PDO */
    private $pdo;

    public function __construct(array $config)
    {
        $charset = 'charset=utf8mb4';
        if (!empty($config['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;%s',
                $config['socket'], $config['database'], $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;%s',
                $config['host'], (int) ($config['port'] ?? 3306), $config['database'], $charset);
        }

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
