<?php

namespace App\Services;

class DeviceIdentifierService
{
    /**
     * Per-process cache so repeated calls in a single request never re-shell-out.
     */
    private static ?string $cachedPrefix = null;

    private static ?string $cachedMachineIdentifier = null;

    public function getPrefix(): string
    {
        if (self::$cachedPrefix !== null) {
            return self::$cachedPrefix;
        }

        $machineIdentifier = $this->getMachineIdentifier();
        $appKey = (string) config('app.key', 'smart-till-pos');

        $hash = hash_hmac('sha256', $machineIdentifier, $appKey);
        $segment = substr($hash, 0, 12);
        $base36 = strtoupper(base_convert($segment, 16, 36));
        $prefix = substr(str_pad($base36, 6, '0', STR_PAD_LEFT), 0, 6);

        if (preg_match('/^[A-Z0-9]{6}$/', $prefix) !== 1) {
            $prefix = 'POS001';
        }

        return self::$cachedPrefix = $prefix;
    }

    private function getMachineIdentifier(): string
    {
        if (self::$cachedMachineIdentifier !== null) {
            return self::$cachedMachineIdentifier;
        }

        // Cross-request cache so we never re-execute system probes after the
        // first install. The previous implementation called wmic on every
        // request, which hangs forever on Windows 11 24H2+ (wmic was removed)
        // and blocked the entire app for 30 seconds per request.
        $cachePath = storage_path('app/device-identifier.cache');
        if (is_file($cachePath)) {
            $cached = trim((string) @file_get_contents($cachePath));
            if ($cached !== '') {
                return self::$cachedMachineIdentifier = $cached;
            }
        }

        $value = $this->probeMachineIdentifier();

        @file_put_contents($cachePath, $value);

        return self::$cachedMachineIdentifier = $value;
    }

    private function probeMachineIdentifier(): string
    {
        $osFamily = PHP_OS_FAMILY;

        if ($osFamily === 'Darwin') {
            $value = $this->runWithTimeout(
                "ioreg -rd1 -c IOPlatformExpertDevice | awk -F'\"' '/IOPlatformUUID/{print \$(NF-1)}'",
                3
            );
            if ($value !== '') {
                return $value;
            }
        }

        if ($osFamily === 'Windows') {
            // reg query is fast, has been available since Windows XP, and
            // (unlike wmic) is still present on Windows 11 24H2 and later.
            $value = $this->runWithTimeout(
                'reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid',
                3
            );
            if ($value !== '') {
                foreach (preg_split("/\r\n|\n|\r/", $value) ?: [] as $line) {
                    if (stripos($line, 'MachineGuid') !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        $candidate = $parts !== false ? (string) end($parts) : '';
                        if ($candidate !== '') {
                            return $candidate;
                        }
                    }
                }
            }
        }

        if ($osFamily === 'Linux') {
            foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
                if (is_readable($path)) {
                    $value = trim((string) @file_get_contents($path));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        $fallback = trim((string) php_uname('n')).'|'.trim((string) gethostname()).'|'.PHP_OS;

        return $fallback !== '' ? $fallback : 'unknown-device';
    }

    /**
     * Run a shell command with a hard wall-clock timeout. Returns the trimmed
     * stdout, or an empty string on timeout / failure / empty output.
     *
     * shell_exec() has no timeout, so a hung child process (e.g. removed
     * wmic) blocks until PHP's max_execution_time kills the entire request.
     */
    private function runWithTimeout(string $command, int $timeoutSeconds): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);

            if (! $status['running']) {
                break;
            }

            usleep(50_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);

        if (proc_get_status($process)['running']) {
            @proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($process);

        return trim($stdout);
    }
}
