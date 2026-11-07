<?php
declare(strict_types=1);

// CLI: SCHOOL_CONFIG=/path/to/thinkle-config.php php bin/migrate.php
require dirname(__DIR__) . '/src/autoload.php';

$applied = (new School\Core\Migrator(School\Core\Db::get(), dirname(__DIR__) . '/migrations'))->run();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Nothing to apply.' . PHP_EOL;
