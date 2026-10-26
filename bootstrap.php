<?php

declare(strict_types=1);

// Zero-dependency PSR-4 autoloader, matching deployment-system's tooling style.
// The agent kernel must run from any workspace checkout without composer install.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Workspace\\Agents\\Tests\\' => __DIR__.'/tests/',
        'Workspace\\Agents\\' => __DIR__.'/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
