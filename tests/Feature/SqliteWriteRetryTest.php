<?php

use App\Support\SqliteWriteRetry;
use Illuminate\Database\QueryException;

it('returns the callback result when it succeeds on the first try', function (): void {
    $calls = 0;

    $result = SqliteWriteRetry::run(function () use (&$calls): string {
        $calls++;

        return 'ok';
    });

    expect($result)->toBe('ok');
    expect($calls)->toBe(1);
});

it('retries on a SQLite database is locked error and eventually succeeds', function (): void {
    $calls = 0;

    $result = SqliteWriteRetry::run(function () use (&$calls): string {
        $calls++;

        if ($calls < 3) {
            throw new QueryException(
                'sqlite',
                'INSERT INTO sales (...) VALUES (...)',
                [],
                new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'),
            );
        }

        return 'persisted';
    });

    expect($result)->toBe('persisted');
    expect($calls)->toBe(3);
});

it('rethrows the original exception when the lock never clears within the attempt budget', function (): void {
    $calls = 0;
    $thrown = null;

    try {
        SqliteWriteRetry::run(function () use (&$calls): void {
            $calls++;
            throw new QueryException(
                'sqlite',
                'INSERT INTO sales (...) VALUES (...)',
                [],
                new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'),
            );
        }, maxAttempts: 3);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);
    // Stops at the configured limit instead of looping forever.
    expect($calls)->toBe(3);
});

it('does not retry on a non-lock QueryException (real bugs must surface)', function (): void {
    $calls = 0;
    $thrown = null;

    try {
        SqliteWriteRetry::run(function () use (&$calls): void {
            $calls++;
            throw new QueryException(
                'sqlite',
                'INSERT INTO sales (typo) VALUES (...)',
                [],
                new RuntimeException('SQLSTATE[HY000]: General error: 1 no such column: typo'),
            );
        });
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);
    // Real bugs surface on the first attempt — no retry storm.
    expect($calls)->toBe(1);
});

it('recognises SQLITE_BUSY-style messages as lock errors', function (): void {
    $cases = [
        'SQLSTATE[HY000]: General error: 5 database is locked',
        'SQLSTATE[HY000]: General error: 6 database table is locked',
        'SQLSTATE[HY000]: General error: 5',
        'SQLITE_BUSY: another process is writing',
    ];

    foreach ($cases as $message) {
        expect(SqliteWriteRetry::isLockError(new RuntimeException($message)))
            ->toBeTrue("Expected `{$message}` to be detected as a lock error");
    }

    // Sanity check: an unrelated message must not match.
    expect(SqliteWriteRetry::isLockError(new RuntimeException('unique constraint violated')))
        ->toBeFalse();
});
