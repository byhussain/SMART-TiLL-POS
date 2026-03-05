<?php

namespace App\Services;

class DeviceIdentifierService
{
    public function getPrefix(): string
    {
        $machineIdentifier = $this->getMachineIdentifier();
        $appKey = (string) config('app.key', 'smart-till-pos');

        $hash = hash_hmac('sha256', $machineIdentifier, $appKey);
        $segment = substr($hash, 0, 12);
        $base36 = strtoupper(base_convert($segment, 16, 36));
        $prefix = substr(str_pad($base36, 6, '0', STR_PAD_LEFT), 0, 6);

        if (preg_match('/^[A-Z0-9]{6}$/', $prefix) === 1) {
            return $prefix;
        }

        return 'POS001';
    }

    private function getMachineIdentifier(): string
    {
        $osFamily = PHP_OS_FAMILY;

        if ($osFamily === 'Darwin') {
            $value = trim((string) @shell_exec("ioreg -rd1 -c IOPlatformExpertDevice | awk -F\\\" '/IOPlatformUUID/{print \$(NF-1)}'"));
            if ($value !== '') {
                return $value;
            }
        }

        if ($osFamily === 'Windows') {
            $value = trim((string) @shell_exec('wmic csproduct get uuid'));
            $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $value))));
            if (count($lines) >= 2 && strtoupper($lines[0]) === 'UUID') {
                return (string) $lines[1];
            }
        }

        if ($osFamily === 'Linux') {
            $paths = ['/etc/machine-id', '/var/lib/dbus/machine-id'];
            foreach ($paths as $path) {
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
}
