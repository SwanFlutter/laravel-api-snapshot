<?php

declare(strict_types=1);

/**
 * Bootstraps the test run with the framework dependency installed either by
 * the package itself (vendor/) or by the local development harness (devtools/).
 */
$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../devtools/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $map = [
        'SwanFlutter\\LaravelApiSnapshot\\Tests\\' => __DIR__ . '/',
        'SwanFlutter\\LaravelApiSnapshot\\'        => __DIR__ . '/../src/',
    ];

    foreach ($map as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $directory . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});

if (! class_exists(Illuminate\Routing\Router::class)) {
    fwrite(STDERR, "Missing dependency: run composer install (or create devtools/vendor) before testing.\n");
    exit(1);
}
