<?php

it('forces safer queue, session, and cache defaults for sqlite desktop runtime', function (): void {
    $original = [
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: null,
        'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION') ?: null,
        'SESSION_DRIVER' => getenv('SESSION_DRIVER') ?: null,
        'CACHE_STORE' => getenv('CACHE_STORE') ?: null,
    ];

    try {
        putenv('DB_CONNECTION=sqlite');
        putenv('QUEUE_CONNECTION=database');
        putenv('SESSION_DRIVER=database');
        putenv('CACHE_STORE=database');

        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['QUEUE_CONNECTION'] = 'database';
        $_ENV['SESSION_DRIVER'] = 'database';
        $_ENV['CACHE_STORE'] = 'database';

        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['QUEUE_CONNECTION'] = 'database';
        $_SERVER['SESSION_DRIVER'] = 'database';
        $_SERVER['CACHE_STORE'] = 'database';

        $queueConfig = require config_path('queue.php');
        $sessionConfig = require config_path('session.php');
        $cacheConfig = require config_path('cache.php');

        expect($queueConfig['default'])->toBe('background');
        expect($sessionConfig['driver'])->toBe('file');
        expect($cacheConfig['default'])->toBe('file');
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
});

it('keeps non-sqlite runtime defaults unchanged', function (): void {
    $original = [
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: null,
        'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION') ?: null,
        'SESSION_DRIVER' => getenv('SESSION_DRIVER') ?: null,
        'CACHE_STORE' => getenv('CACHE_STORE') ?: null,
    ];

    try {
        putenv('DB_CONNECTION=mysql');
        putenv('QUEUE_CONNECTION=database');
        putenv('SESSION_DRIVER=database');
        putenv('CACHE_STORE=database');

        $_ENV['DB_CONNECTION'] = 'mysql';
        $_ENV['QUEUE_CONNECTION'] = 'database';
        $_ENV['SESSION_DRIVER'] = 'database';
        $_ENV['CACHE_STORE'] = 'database';

        $_SERVER['DB_CONNECTION'] = 'mysql';
        $_SERVER['QUEUE_CONNECTION'] = 'database';
        $_SERVER['SESSION_DRIVER'] = 'database';
        $_SERVER['CACHE_STORE'] = 'database';

        $queueConfig = require config_path('queue.php');
        $sessionConfig = require config_path('session.php');
        $cacheConfig = require config_path('cache.php');

        expect($queueConfig['default'])->toBe('database');
        expect($sessionConfig['driver'])->toBe('database');
        expect($cacheConfig['default'])->toBe('database');
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
});
