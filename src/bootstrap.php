<?php

declare(strict_types=1);

/**
 * Zero-dependency autoloader.
 *
 * There is no Composer install step: the whole tutorial runs on a stock PHP
 * 8.1+ install. If you prefer Composer, the same class names are exposed
 * through composer.json.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Webhook\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
