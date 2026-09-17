<?php

/**
 * Minimal hand-written PSR-4 autoloader — this scaffold has zero third-party
 * package dependencies (see composer.json's empty require list beyond the
 * PHP version constraint), so a full Composer install is unnecessary; this
 * file exists only so `require __DIR__ . '/../vendor/autoload.php'` in
 * public/index.php resolves both locally and on any host (e.g. Vercel's
 * PHP runtime) that doesn't run `composer install` during its build step.
 * If real dependencies are ever added, replace this with a genuine
 * `composer install` and delete this file.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Rider\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
