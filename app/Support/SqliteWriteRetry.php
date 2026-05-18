<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Retry a write closure that may transiently fail with a SQLite lock error.
 *
 * On Windows POS installs, "database is locked" / "database table is locked"
 * errors surface when two writers race for the same SQLite file — typically
 * the user saving a sale at the exact moment the background sync worker is
 * mid-flush. WAL mode + busy_timeout already protect against the common
 * cases, but a few hot paths (observer writes inside the outer transaction,
 * the LocalIdentifierService sequence allocator) can still throw after the
 * busy_timeout window expires.
 *
 * This helper catches those transient lock errors specifically, sleeps a
 * small jitter to break the lockstep between contending processes, and
 * retries up to a handful of times before giving up. Any non-lock exception
 * is re-thrown immediately — we never want to mask real bugs.
 */
class SqliteWriteRetry
{
    /**
     * Run a write closure, retrying on transient SQLite lock errors.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback, int $maxAttempts = 8)
    {
        $attempt = 0;

        beginning:
        try {
            return $callback();
        } catch (QueryException $exception) {
            $attempt++;

            if ($attempt >= $maxAttempts || ! self::isLockError($exception)) {
                throw $exception;
            }

            // Exponential backoff with jitter: ~50ms, ~100ms, ~200ms, … capped.
            // The jitter prevents two competing writers from locking in step.
            $baseMs = min(800, 25 * (2 ** $attempt));
            $jitterMs = random_int(0, 100);
            usleep(($baseMs + $jitterMs) * 1000);

            goto beginning;
        }
    }

    /**
     * SQLite "database is locked" / "database table is locked" / busy errors
     * raised by PDO surface as QueryException with SQLSTATE HY000 and a
     * message that includes "locked" or "busy". We match generously so that
     * wrapped exceptions from PDO are also caught.
     */
    public static function isLockError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'database is locked')) {
            return true;
        }

        if (str_contains($message, 'database table is locked')) {
            return true;
        }

        if (str_contains($message, 'sqlite_busy')) {
            return true;
        }

        // Some PDO builds report just "general error: 5" / "general error: 6"
        // (5 = SQLITE_BUSY, 6 = SQLITE_LOCKED) without the word "locked".
        if (preg_match('/general error:\s*[56]\b/', $message) === 1) {
            return true;
        }

        return false;
    }
}
