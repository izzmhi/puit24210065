<?php

declare(strict_types=1);

// Never show PHP errors to API clients: they can leak paths and configuration.
// Errors are written to the server error log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Minimal PSR-4 style autoloader: App\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
