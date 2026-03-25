@php
    /** @var \App\Models\AppRuntimeState $runtimeState */
    $runtimeState = app(\App\Services\RuntimeStateService::class)->get();
    $isCloudConnected = $runtimeState->mode === 'cloud'
        && (bool) $runtimeState->cloud_token_present
        && filled($runtimeState->cloud_base_url)
        && filled($runtimeState->cloud_token);

    $hasSyncQueueActivity = false;
    $hasSyncQueueFailures = false;

    if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
        $hasSyncQueueActivity = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('payload', 'like', '%SyncCloudStoreData%')
            ->exists();
    }

    $cloudSyncService = app(\App\Services\CloudSyncService::class);
    $activeStoreId = (int) ($runtimeState->active_store_id ?? 0);
    $syncErrorOverview = $cloudSyncService->getSyncErrorOverview($activeStoreId);
    $outboxErrorOverview = $cloudSyncService->getOutboxErrorOverviewForStore($activeStoreId);
    $moduleSyncErrors = $syncErrorOverview['module_errors'] ?? [];
    $errorRecords = collect($syncErrorOverview['error_records'] ?? []);
    $outboxErrorRecords = collect($outboxErrorOverview['records'] ?? []);
    $outboxFailedCount = (int) ($outboxErrorOverview['total_failed'] ?? 0);

    $recentJobErrors = collect();
    if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
        $isFailedJobForActiveStore = function (mixed $payload, int $storeId): bool {
            if (! is_string($payload) || $storeId <= 0) {
                return false;
            }

            $commandPayload = $payload;
            $decodedPayload = json_decode($payload, true);
            if (is_array($decodedPayload)) {
                $decodedCommand = data_get($decodedPayload, 'data.command');
                if (is_string($decodedCommand) && $decodedCommand !== '') {
                    $commandPayload = $decodedCommand;
                }
            }

            return preg_match('/s:7:"storeId";i:(\d+);/', $commandPayload, $matches) === 1
                && (int) ($matches[1] ?? 0) === $storeId;
        };

        $recentJobErrors = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('payload', 'like', '%SyncCloudStoreData%')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'payload', 'exception', 'failed_at'])
            ->filter(fn ($job): bool => $isFailedJobForActiveStore($job->payload ?? null, $activeStoreId))
            ->take(3)
            ->values();

        $hasSyncQueueFailures = $recentJobErrors->isNotEmpty();
    }

    $hasSyncError = (bool) ($syncErrorOverview['has_sync_errors'] ?? false) || $outboxFailedCount > 0 || $hasSyncQueueFailures;
    $bootstrapStatus = (string) ($runtimeState->bootstrap_status ?? 'not_started');
    $bootstrapProgressPercent = (int) ($runtimeState->bootstrap_progress_percent ?? 0);
    $bootstrapProgressLabel = (string) ($runtimeState->bootstrap_progress_label ?? '');
    $isBootstrapping = in_array($bootstrapStatus, ['downloading', 'installing'], true);
    $isSyncing = ! $hasSyncError && $hasSyncQueueActivity && ! $isBootstrapping;

    $iconColorClass = 'text-red-600';
    if ($isCloudConnected) {
        $iconColorClass = $hasSyncError ? 'text-red-600' : (($isSyncing || $isBootstrapping) ? 'text-amber-500' : 'text-emerald-600');
    }

    $moduleDefinitions = $cloudSyncService->getSyncModules();
    $moduleStats = $cloudSyncService->getModuleStats((int) ($runtimeState->active_store_id ?? 0));
@endphp

<div
    x-data="{
        open: false,
        isSyncSubmitting: false,
        syncingModules: {},
        isQueueSyncing: @js($isSyncing),
        isBootstrapping: @js($isBootstrapping),
        bootstrapStatus: @js($bootstrapStatus),
        bootstrapProgressPercent: @js($bootstrapProgressPercent),
        bootstrapProgressLabel: @js($bootstrapProgressLabel),
        hasRuntimeErrors: @js($hasSyncError),
        moduleQueueSyncing: @js(collect(array_keys($moduleDefinitions))->mapWithKeys(fn (string $key): array => [$key => false])->all()),
        pollTimer: null,
        messageTimer: null,
        wasQueueSyncing: @js($isSyncing),
        dismissedErrors: false,
        lastMessage: '',
        lastMessageType: 'success',
        bootstrapHeadline() {
            return this.bootstrapStatus === 'installing' ? 'Installing store data' : 'Downloading store data';
        },
        setMessage(message, type = 'success', autoHideMs = 0) {
            this.lastMessage = message;
            this.lastMessageType = type;

            if (this.messageTimer) {
                window.clearTimeout(this.messageTimer);
                this.messageTimer = null;
            }

            if (autoHideMs > 0) {
                this.messageTimer = window.setTimeout(() => {
                    this.lastMessage = '';
                    this.messageTimer = null;
                }, autoHideMs);
            }
        },
        init() {
            this.pollSyncStatus();
            this.pollTimer = window.setInterval(() => this.pollSyncStatus(), 3000);
        },
        async pollSyncStatus() {
            try {
                const response = await fetch(@js(route('startup.cloud.sync-status')), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json().catch(() => ({}));
                this.isQueueSyncing = !!data?.is_syncing;
                this.isBootstrapping = !!data?.is_bootstrapping;
                this.bootstrapStatus = data?.bootstrap_status ?? 'not_started';
                this.bootstrapProgressPercent = Number(data?.bootstrap_progress_percent ?? 0);
                this.bootstrapProgressLabel = data?.bootstrap_progress_label ?? '';
                this.hasRuntimeErrors = !!data?.has_errors;

                const incoming = data?.module_syncing ?? {};
                Object.keys(this.moduleQueueSyncing).forEach((key) => {
                    this.moduleQueueSyncing[key] = !!incoming[key];
                });

                if (this.wasQueueSyncing && !this.isQueueSyncing && !this.isBootstrapping) {
                    if (this.hasRuntimeErrors) {
                        this.setMessage('Sync completed with errors. Please review error records.', 'error');
                    } else {
                        this.setMessage('Sync completed successfully.', 'success', 5000);
                    }
                }

                if (!this.isQueueSyncing && this.hasRuntimeErrors) {
                    this.dismissedErrors = false;
                }

                this.wasQueueSyncing = this.isQueueSyncing;
            } catch (_error) {
            }
        },
        async request(url, body, onDone = null) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': @js(csrf_token()),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = typeof data?.message === 'string' && data.message.trim() !== ''
                    ? data.message
                    : 'Unable to start sync.';

                throw new Error(message);
            }

            this.setMessage(
                typeof data?.message === 'string' && data.message.trim() !== ''
                    ? data.message
                    : 'Sync queued in background.',
                'success'
            );
            if (typeof onDone === 'function') {
                onDone();
            }

            await this.pollSyncStatus();
        },
        async syncNow() {
            if (this.isSyncSubmitting || this.isQueueSyncing || this.isBootstrapping) {
                return;
            }

            this.isSyncSubmitting = true;
            this.dismissedErrors = true;
            this.lastMessage = '';

            try {
                await this.request(@js(route('startup.cloud.sync-now')), {});
            } catch (error) {
                this.setMessage(error.message ?? 'Unable to start sync.', 'error');
            } finally {
                this.isSyncSubmitting = false;
            }
        },
        async syncModule(moduleKey) {
            if (this.syncingModules[moduleKey] || this.isQueueSyncing || this.isBootstrapping || this.moduleQueueSyncing[moduleKey]) {
                return;
            }

            this.syncingModules[moduleKey] = true;
            this.lastMessage = '';

            try {
                await this.request(@js(route('startup.cloud.sync-module')), { module: moduleKey });
            } catch (error) {
                this.setMessage(error.message ?? 'Unable to start module sync.', 'error');
            } finally {
                this.syncingModules[moduleKey] = false;
            }
        },
    }"
    x-init="init()"
    class="relative pl-2"
>
    <button
        type="button"
        @click="open = true"
        class="inline-flex h-10 w-10 items-center justify-center rounded-md transition"
        title="Cloud sync status"
        aria-label="Open cloud sync status"
    >
        @if ($isCloudConnected)
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 {{ $iconColorClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M18 10a6 6 0 0 0-11.3-2.6A4.5 4.5 0 0 0 7 16h9a4 4 0 0 0 2-7.5"/>
            </svg>
        @else
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 {{ $iconColorClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M18 10a6 6 0 0 0-11.3-2.6A4.5 4.5 0 0 0 7 16h9a4 4 0 0 0 2-7.5"/>
                <path d="m3 3 18 18"/>
            </svg>
        @endif
    </button>

    <div x-cloak class="fixed inset-0 z-50" :class="{ 'pointer-events-none': !open }" @keydown.escape.window="open = false">
        <div
            x-show="open"
            x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-slate-950/50"
            @click="open = false"
        ></div>

        <aside
            x-show="open"
            x-transition:enter="transform transition ease-out duration-300"
            x-transition:enter-start="translate-x-full opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transform transition ease-in duration-200"
            x-transition:leave-start="translate-x-0 opacity-100"
            x-transition:leave-end="translate-x-full opacity-0"
            class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900"
            @click.outside="open = false"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-white/10">
                <h2 class="text-base font-semibold text-slate-900 dark:text-gray-100">Cloud Sync Status</h2>
                <button
                    type="button"
                    @click="open = false"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                    aria-label="Close cloud sync status"
                    title="Close"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-hidden px-5 py-5">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-gray-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-gray-400">Connection</p>
                    <p class="mt-1 text-sm font-semibold {{ $isCloudConnected ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $isCloudConnected ? 'Connected' : 'Not connected' }}
                    </p>
                    @if ($isCloudConnected)
                        <p
                            class="mt-1 text-xs font-medium"
                            :class="hasRuntimeErrors ? 'text-red-600' : ((isQueueSyncing || isBootstrapping) ? 'text-amber-600' : 'text-emerald-600')"
                            x-text="hasRuntimeErrors ? 'Sync has errors' : (isBootstrapping ? bootstrapHeadline() : (isQueueSyncing ? 'Sync in progress' : 'All synced'))"
                        ></p>
                        <div x-show="isBootstrapping" class="mt-3">
                            <div class="flex items-center justify-between text-xs text-slate-500 dark:text-gray-400">
                                <span x-text="bootstrapProgressLabel || 'Preparing bootstrap'"></span>
                                <span x-text="`${bootstrapProgressPercent}%`"></span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-gray-800">
                                <div class="h-full rounded-full bg-amber-500 transition-all duration-300" :style="`width: ${bootstrapProgressPercent}%`"></div>
                            </div>
                            <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">
                                Large first-time imports can take several minutes. The POS opens normally after installation finishes.
                            </p>
                        </div>
                    @endif
                    <p class="mt-2 text-xs text-slate-500 dark:text-gray-400" x-show="!isBootstrapping">
                        {{ $runtimeState->last_synced_at ? 'Last synced: '.$runtimeState->last_synced_at->diffForHumans() : 'Last synced: never' }}
                    </p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-gray-400" x-show="isBootstrapping">
                        The POS is blocked until this first store install finishes.
                    </p>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden">
                    @if ($isCloudConnected)
                        <div class="flex min-h-0 flex-1 flex-col rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-gray-900">
                            <div class="border-b border-slate-200 px-4 py-3 dark:border-white/10">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">Modules</p>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto">
                                @foreach ($moduleDefinitions as $moduleKey => $module)
                                    @php
                                        $moduleStat = $moduleStats[$moduleKey] ?? ['total' => 0, 'synced' => 0, 'errors' => 0];
                                        $moduleErrorCount = (int) ($moduleSyncErrors[$moduleKey] ?? 0);
                                        $latestModuleError = $errorRecords
                                            ->first(fn (array $row): bool => ($row['module'] ?? null) === $moduleKey);
                                    @endphp

                                    <div class="border-b border-slate-100 px-4 py-3 last:border-b-0 dark:border-white/10">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-slate-900 dark:text-gray-100">{{ $module['label'] }}</p>
                                                <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">
                                                    {{ (int) $moduleStat['synced'] }} / {{ (int) $moduleStat['total'] }} synced
                                                    @if ($moduleErrorCount > 0)
                                                        <span class="ml-1 text-red-600">({{ $moduleErrorCount }} sync errors)</span>
                                                    @endif
                                                </p>
                                                @if ($latestModuleError)
                                                    <p class="mt-1 text-xs text-red-600 break-words">{{ \Illuminate\Support\Str::limit((string) ($latestModuleError['sync_error'] ?? ''), 160) }}</p>
                                                @endif
                                            </div>

                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                                                :disabled="isSyncSubmitting || isQueueSyncing || isBootstrapping || !!syncingModules[@js($moduleKey)] || !!moduleQueueSyncing[@js($moduleKey)]"
                                                @click="syncModule(@js($moduleKey))"
                                            >
                                                <svg
                                                    x-show="!!syncingModules[@js($moduleKey)] || !!moduleQueueSyncing[@js($moduleKey)] || isBootstrapping"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-3 w-3 animate-spin"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    aria-hidden="true"
                                                >
                                                    <path d="M12 2v4m0 12v4m10-10h-4M6 12H2m16.95 6.95-2.83-2.83M7.88 7.88 5.05 5.05m13.9 0-2.83 2.83M7.88 16.12l-2.83 2.83"/>
                                                </svg>
                                                <span x-text="(syncingModules[@js($moduleKey)] || moduleQueueSyncing[@js($moduleKey)] || isBootstrapping) ? 'Syncing...' : 'Sync'"></span>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($hasSyncError)
                        <div x-show="hasRuntimeErrors && !dismissedErrors" class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-500/40 dark:bg-red-500/10">
                            <p class="text-xs uppercase tracking-wide text-red-600">Error records (selected store)</p>

                            @if ($errorRecords->isEmpty() && $outboxErrorRecords->isEmpty() && $recentJobErrors->isEmpty())
                                <p class="mt-2 text-xs text-red-700 dark:text-red-200">
                                    Errors were detected, but no detailed message is available yet.
                                </p>
                            @endif

                            @if ($errorRecords->isNotEmpty())
                                <ul class="mt-3 max-h-56 space-y-2 overflow-y-auto text-xs text-red-700 dark:text-red-200">
                                    @foreach ($errorRecords as $errorRow)
                                        <li class="rounded-lg border border-red-200 bg-white px-3 py-2 dark:border-red-500/40 dark:bg-red-900/20">
                                            <p class="font-medium">
                                                {{ (string) ($errorRow['table'] ?? 'unknown') }}
                                                {{ ($errorRow['local_id'] ?? null) ? ' #'.$errorRow['local_id'] : '' }}
                                                @if (! empty($errorRow['server_id']))
                                                    <span class="text-red-500">(server: {{ $errorRow['server_id'] }})</span>
                                                @endif
                                            </p>
                                            <p class="mt-1 break-words">
                                                {{ trim((string) ($errorRow['sync_error'] ?? '')) !== '' ? (string) $errorRow['sync_error'] : 'Operation failed with no error text returned.' }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($outboxErrorRecords->isNotEmpty())
                                <ul class="mt-3 max-h-48 space-y-2 overflow-y-auto text-xs text-red-700 dark:text-red-200">
                                    @foreach ($outboxErrorRecords as $outboxError)
                                        <li class="rounded-lg border border-red-200 bg-white px-3 py-2 dark:border-red-500/40 dark:bg-red-900/20">
                                            <p class="font-medium">
                                                Outbox #{{ $outboxError['id'] ?? '' }}
                                                @if (! empty($outboxError['entity_type']))
                                                    <span class="text-red-500">({{ $outboxError['entity_type'] }})</span>
                                                @endif
                                            </p>
                                            <p class="mt-1 break-words">
                                                {{ (string) ($outboxError['error'] ?? 'Failed without error details.') }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($recentJobErrors->isNotEmpty())
                                <ul class="mt-3 max-h-40 space-y-2 overflow-y-auto text-xs text-red-700 dark:text-red-200">
                                    @foreach ($recentJobErrors as $jobError)
                                        <li class="rounded-lg border border-red-200 bg-white px-3 py-2 dark:border-red-500/40 dark:bg-red-900/20">
                                            <p class="font-medium">Background sync job #{{ $jobError->id }}</p>
                                            <p class="mt-1 break-words">
                                                {{
                                                    \Illuminate\Support\Str::limit(
                                                        trim((string) \Illuminate\Support\Str::before((string) ($jobError->exception ?? ''), "\n")) !== ''
                                                            ? trim((string) \Illuminate\Support\Str::before((string) ($jobError->exception ?? ''), "\n"))
                                                            : 'Background sync job failed without exception details.',
                                                        220
                                                    )
                                                }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <div class="shrink-0 border-t border-slate-200 bg-white px-5 py-4 dark:border-white/10 dark:bg-gray-900">
                <div class="space-y-2">
                    <template x-if="lastMessage">
                        <p
                            class="rounded-md px-3 py-2 text-xs"
                            :class="lastMessageType === 'error'
                                ? 'border border-red-200 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200'
                                : 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200'"
                            x-text="lastMessage"
                        ></p>
                    </template>

                    @if ($isCloudConnected)
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70"
                            :disabled="isSyncSubmitting || isQueueSyncing || isBootstrapping"
                            @click="syncNow"
                        >
                            <svg
                                x-show="isSyncSubmitting || isQueueSyncing || isBootstrapping"
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                aria-hidden="true"
                            >
                                <path d="M12 2v4m0 12v4m10-10h-4M6 12H2m16.95 6.95-2.83-2.83M7.88 7.88 5.05 5.05m13.9 0-2.83 2.83M7.88 16.12l-2.83 2.83"/>
                            </svg>
                            <span x-text="isBootstrapping ? bootstrapHeadline() : ((isSyncSubmitting || isQueueSyncing) ? 'Syncing...' : 'Sync Now')"></span>
                        </button>
                    @else
                        <a href="{{ route('startup.cloud.form') }}" class="block w-full rounded-lg bg-blue-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-400">
                            Connect to Cloud
                        </a>
                    @endif

                    <a href="{{ route('startup.cloud.sync-log') }}" class="block w-full rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                        Open Sync Log
                    </a>
                </div>
            </div>
        </aside>
    </div>
</div>
