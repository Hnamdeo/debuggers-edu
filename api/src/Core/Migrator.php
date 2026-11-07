<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Runs migrations/*.sql in name order, each once, and records them in schema_migrations.
 * Files are never edited after release: a change is always a new, higher-numbered file.
 */
final class Migrator
{
    public function __construct(private readonly Db $db, private readonly string $dir) {}

    /** @return list<string> files applied now */
    public function run(): array
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) continue;
            foreach (self::statements((string)file_get_contents($file)) as $sql) {
                $this->db->pdo->exec($sql);
            }
            $this->db->insert('schema_migrations', ['name' => $name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Split a file on semicolons at line ends, skipping -- comments. */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), fn($l) => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*(\n|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}
