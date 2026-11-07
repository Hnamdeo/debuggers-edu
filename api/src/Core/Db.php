<?php
declare(strict_types=1);

namespace School\Core;

use PDO;

/** Thin PDO wrapper: prepared statements only, so user input never becomes SQL. */
final class Db
{
    private static ?self $instance = null;

    public function __construct(public readonly PDO $pdo) {}

    public static function get(): self
    {
        if (self::$instance) return self::$instance;
        $c = Config::load();
        $pdo = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
        $pdo->exec("SET time_zone = '+05:30'");
        return self::$instance = new self($pdo);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public function one(string $sql, array $args = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $args = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $args = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    /** Insert a row from column => value and return its new id. */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?')),
        );
        $this->exec($sql, array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    /** Update columns of rows matching school_id + id. Tenant id is always part of the filter. */
    public function update(string $table, int $schoolId, int $id, array $row): int
    {
        if (!$row) return 0;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($row)));
        return $this->exec("UPDATE `$table` SET $set WHERE school_id = ? AND id = ?", [...array_values($row), $schoolId, $id]);
    }

    /** @template T @param callable():T $fn @return T */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) return $fn();
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
