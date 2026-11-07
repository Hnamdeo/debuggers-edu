<?php
declare(strict_types=1);

// PSR-4 style autoloader: School\Core\Router -> src/Core/Router.php. No Composer needed on shared hosting.
spl_autoload_register(static function (string $class): void {
    $prefix = 'School\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});
