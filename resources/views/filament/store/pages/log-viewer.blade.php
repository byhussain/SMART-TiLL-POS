<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Log files
            </x-slot>
            <x-slot name="description">
                Select a daily log file to inspect. Press <kbd class="rounded border border-gray-300 px-1 py-0.5 font-mono text-[10px] dark:border-gray-600">⌘L</kbd> to open or close this page.
            </x-slot>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="w-full lg:max-w-md">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedLog">
                            @forelse ($logFiles as $file)
                                <option value="{{ $file }}">{{ $file }}</option>
                            @empty
                                <option value="">No log files found</option>
                            @endforelse
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    @if ($selectedLog)
                        <x-filament::badge color="gray">
                            Size: {{ number_format(($selectedLogSize ?? 0) / 1024, 1) }} KB
                        </x-filament::badge>
                        @if ($selectedLogUpdatedAt)
                            <x-filament::badge color="gray">
                                Updated: {{ $selectedLogUpdatedAt }}
                            </x-filament::badge>
                        @endif
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        size="sm"
                        color="danger"
                        :icon="\Filament\Support\Icons\Heroicon::Trash"
                        x-on:click.prevent="if (confirm('Clear the selected log file?')) { $wire.clearSelectedLog() }"
                        :disabled="! $selectedLog"
                    >
                        Clear log
                    </x-filament::button>
                    <x-filament::button wire:click="refreshLogs" size="sm" color="gray" :icon="\Filament\Support\Icons\Heroicon::ArrowPath">
                        Refresh
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @php
            $filters = [
                ['key' => 'all', 'label' => 'All', 'color' => 'gray'],
                ['key' => 'errors', 'label' => 'Errors', 'color' => 'red'],
                ['key' => 'warnings', 'label' => 'Warnings', 'color' => 'amber'],
                ['key' => 'info', 'label' => 'Info', 'color' => 'sky'],
                ['key' => 'debug', 'label' => 'Debug', 'color' => 'slate'],
                ['key' => 'other', 'label' => 'Other', 'color' => 'zinc'],
            ];

            $styles = [
                'errors' => [
                    'card' => 'border-red-200 bg-red-50/70 dark:border-red-900/40 dark:bg-red-950/30',
                    'badge' => 'bg-red-600 text-white',
                ],
                'warnings' => [
                    'card' => 'border-amber-200 bg-amber-50/70 dark:border-amber-900/40 dark:bg-amber-950/30',
                    'badge' => 'bg-amber-500 text-white',
                ],
                'info' => [
                    'card' => 'border-sky-200 bg-sky-50/70 dark:border-sky-900/40 dark:bg-sky-950/30',
                    'badge' => 'bg-sky-600 text-white',
                ],
                'debug' => [
                    'card' => 'border-slate-200 bg-slate-50/70 dark:border-slate-800 dark:bg-slate-950/30',
                    'badge' => 'bg-slate-600 text-white',
                ],
                'other' => [
                    'card' => 'border-zinc-200 bg-zinc-50/70 dark:border-zinc-800 dark:bg-zinc-950/30',
                    'badge' => 'bg-zinc-600 text-white',
                ],
            ];

            $entries = $this->filteredEntries;
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                Filters
            </x-slot>
            <x-slot name="description">
                Filter by severity and review counts at a glance.
            </x-slot>

            <div class="flex flex-wrap items-center gap-3">
                @foreach ($filters as $filter)
                    @php
                        $isActive = $levelFilter === $filter['key'];
                        $count = $levelCounts[$filter['key']] ?? 0;
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('levelFilter', '{{ $filter['key'] }}')"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-950
                            {{ $isActive ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-gray-700' }}"
                    >
                        <span>{{ $filter['label'] }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            {{ $count }}
                        </span>
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        <div class="space-y-3">
            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Showing {{ count($entries) }} of {{ $totalFilteredEntries }} entries (latest first)</span>
                @if (! empty($selectedLog))
                    <span>Current filter: {{ ucfirst($levelFilter) }}</span>
                @endif
            </div>

            @forelse ($entries as $entry)
                @php
                    $group = $entry['group'] ?? 'other';
                    $cardClass = $styles[$group]['card'] ?? $styles['other']['card'];
                    $badgeClass = $styles[$group]['badge'] ?? $styles['other']['badge'];
                @endphp
                <div class="rounded-lg border p-4 shadow-sm {{ $cardClass }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide {{ $badgeClass }}">
                                    {{ strtoupper($entry['level'] ?? 'other') }}
                                </span>
                                @if (! empty($entry['timestamp']))
                                    <span class="text-gray-600 dark:text-gray-300">{{ $entry['timestamp'] }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-gray-900 dark:text-gray-100">
                                {{ $entry['message'] }}
                            </p>
                        </div>
                    </div>

                    @if (! empty($entry['context']))
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                Stack trace / context
                            </summary>
                            <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap text-xs leading-relaxed text-gray-800 dark:text-gray-200">{{ implode("\n", $entry['context']) }}</pre>
                        </details>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                    No log entries match the selected filter.
                </div>
            @endforelse

            @if (count($entries) < $totalFilteredEntries)
                <div class="flex justify-center pt-2">
                    <x-filament::button color="gray" wire:click="loadMore">
                        Load more
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
