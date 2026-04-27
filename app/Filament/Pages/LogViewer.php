<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;
use SplFileObject;

class LogViewer extends Page
{
    private const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    private const WARNING_LEVELS = ['warning'];

    private const INFO_LEVELS = ['info', 'notice'];

    private const DEBUG_LEVELS = ['debug'];

    protected static string $view = 'filament.store.pages.log-viewer';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Log Viewer';

    public static function getActiveNavigationIcon(): \BackedEnum|Htmlable|null|string
    {
        return Heroicon::DocumentText;
    }

    /** @var array<int, string> */
    public array $logFiles = [];

    public ?string $selectedLog = null;

    public string $logContents = '';

    public ?int $selectedLogSize = null;

    public ?string $selectedLogUpdatedAt = null;

    /** @var array<int, array<string, mixed>> */
    public array $parsedEntries = [];

    /** @var array<string, int> */
    public array $levelCounts = [];

    public string $levelFilter = 'all';

    public int $page = 1;

    public int $perPage = 25;

    public int $totalFilteredEntries = 0;

    public function mount(): void
    {
        $this->loadLogFiles();
        $this->selectDefaultLog();
    }

    public function updatedSelectedLog(): void
    {
        $this->page = 1;
        $this->loadLogContents();
    }

    public function updatedLevelFilter(): void
    {
        $this->page = 1;
        $this->loadLogContents();
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
        $this->loadLogContents();
    }

    public function refreshLogs(): void
    {
        $current = $this->selectedLog;
        $this->loadLogFiles();
        $this->selectedLog = $current;
        $this->selectDefaultLog();
    }

    public function loadMore(): void
    {
        if ($this->page < $this->getTotalPages()) {
            $this->page++;
            $this->loadLogContents();
        }
    }

    public function clearSelectedLog(): void
    {
        if (! $this->selectedLog || ! in_array($this->selectedLog, $this->logFiles, true)) {
            return;
        }

        $path = $this->getLogPath($this->selectedLog);
        if (! $path || ! is_file($path)) {
            return;
        }

        File::put($path, '');
        $this->loadLogContents();

        Notification::make()
            ->title('Log cleared')
            ->success()
            ->send();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredEntriesProperty(): array
    {
        return $this->parsedEntries;
    }

    private function loadLogFiles(): void
    {
        $logDir = storage_path('logs');

        if (! is_dir($logDir)) {
            $this->logFiles = [];

            return;
        }

        $this->logFiles = collect(File::files($logDir))
            ->filter(fn ($file) => $file->getExtension() === 'log')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => $file->getFilename())
            ->values()
            ->all();
    }

    private function selectDefaultLog(): void
    {
        if ($this->selectedLog && in_array($this->selectedLog, $this->logFiles, true)) {
            $this->loadLogContents();

            return;
        }

        if (! empty($this->logFiles)) {
            $preferred = collect($this->logFiles)
                ->first(fn (string $file) => str_starts_with($file, 'laravel'));

            $this->selectedLog = $preferred ?? $this->logFiles[0];
            $this->loadLogContents();
        }
    }

    private function loadLogContents(): void
    {
        if (! $this->selectedLog || ! in_array($this->selectedLog, $this->logFiles, true)) {
            $this->logContents = '';
            $this->selectedLogSize = null;
            $this->selectedLogUpdatedAt = null;
            $this->parsedEntries = [];
            $this->levelCounts = [];
            $this->totalFilteredEntries = 0;

            return;
        }

        $path = $this->getLogPath($this->selectedLog);
        if (! $path || ! is_file($path)) {
            $this->logContents = '';
            $this->selectedLogSize = null;
            $this->selectedLogUpdatedAt = null;
            $this->parsedEntries = [];
            $this->levelCounts = [];
            $this->totalFilteredEntries = 0;

            return;
        }

        $this->logContents = '';
        $this->selectedLogSize = filesize($path) ?: 0;
        $this->selectedLogUpdatedAt = date('Y-m-d H:i:s', filemtime($path));
        $this->buildCountsAndTotals($path);
        $this->loadPageEntries($path);
    }

    private function getLogPath(string $filename): ?string
    {
        $basename = basename($filename);
        if ($basename !== $filename) {
            return null;
        }

        return storage_path('logs'.DIRECTORY_SEPARATOR.$basename);
    }

    private function buildCountsAndTotals(string $path): void
    {
        $this->levelCounts = [
            'all' => 0,
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
            'debug' => 0,
            'other' => 0,
        ];
        $this->totalFilteredEntries = 0;

        $this->iterateLogEntries($path, function (array $entry): void {
            $group = $entry['group'] ?? 'other';
            if (array_key_exists($group, $this->levelCounts)) {
                $this->levelCounts[$group]++;
            } else {
                $this->levelCounts['other']++;
            }
            $this->levelCounts['all']++;

            if ($this->levelFilter === 'all' || $group === $this->levelFilter) {
                $this->totalFilteredEntries++;
            }
        });
    }

    private function loadPageEntries(string $path): void
    {
        $this->parsedEntries = [];

        if ($this->totalFilteredEntries === 0) {
            return;
        }

        $totalPages = $this->getTotalPages();
        if ($this->page > $totalPages) {
            $this->page = $totalPages;
        }

        $start = max($this->totalFilteredEntries - $this->page * $this->perPage, 0);
        $end = $this->totalFilteredEntries - 1;

        $index = 0;
        $pageEntries = [];

        $this->iterateLogEntries($path, function (array $entry) use (&$index, &$pageEntries, $start, $end): void {
            $group = $entry['group'] ?? 'other';
            if ($this->levelFilter !== 'all' && $group !== $this->levelFilter) {
                return;
            }

            if ($index >= $start && $index <= $end) {
                $pageEntries[] = $entry;
            }

            $index++;
        });

        $this->parsedEntries = array_reverse($pageEntries);
    }

    private function resolveLevelGroup(string $level): string
    {
        if (in_array($level, self::ERROR_LEVELS, true)) {
            return 'errors';
        }

        if (in_array($level, self::WARNING_LEVELS, true)) {
            return 'warnings';
        }

        if (in_array($level, self::INFO_LEVELS, true)) {
            return 'info';
        }

        if (in_array($level, self::DEBUG_LEVELS, true)) {
            return 'debug';
        }

        return 'other';
    }

    private function iterateLogEntries(string $path, callable $callback): void
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        $current = null;

        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line === false) {
                break;
            }

            if (preg_match('/^\[(?<timestamp>[^\]]+)\]\s+(?<env>[^.]+)\.(?<level>[A-Z]+):\s(?<message>.*)$/', $line, $matches)) {
                if ($current) {
                    $callback($current);
                }

                $level = strtolower($matches['level']);
                $current = [
                    'timestamp' => $matches['timestamp'],
                    'level' => $level,
                    'message' => $this->sanitizeUtf8($matches['message']),
                    'context' => [],
                    'group' => $this->resolveLevelGroup($level),
                ];

                continue;
            }

            if ($current) {
                $current['context'][] = $this->sanitizeUtf8(rtrim($line, "\r\n"));
            } elseif (trim($line) !== '') {
                $callback([
                    'timestamp' => null,
                    'level' => 'other',
                    'message' => $this->sanitizeUtf8(rtrim($line, "\r\n")),
                    'context' => [],
                    'group' => 'other',
                ]);
            }
        }

        if ($current) {
            $callback($current);
        }
    }

    private function getTotalPages(): int
    {
        if ($this->perPage <= 0) {
            return 1;
        }

        return max(1, (int) ceil($this->totalFilteredEntries / $this->perPage));
    }

    private function sanitizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
