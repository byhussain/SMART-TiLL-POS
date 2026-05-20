<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SyncOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Customer as CoreCustomer;
use SmartTill\Core\Models\Payment as CorePayment;
use SmartTill\Core\Models\Product as CoreProduct;
use SmartTill\Core\Models\PurchaseOrder as CorePurchaseOrder;
use SmartTill\Core\Models\Sale as CoreSale;
use SmartTill\Core\Models\Supplier as CoreSupplier;
use SmartTill\Core\Models\Timezone;
use SmartTill\Core\Models\Variation as CoreVariation;

class CloudSyncService
{
    // Pull as many rows per HTTP roundtrip as the server allows (500). On a
    // 10k-row bootstrap that's 20 round trips instead of 50 — meaningful on
    // high-latency cellular connections where each round trip can be 300ms+.
    private const PULL_PER_PAGE = 500;

    private const MAX_PAGES_PER_CHUNK = 3;

    private const MODULE_DEFINITIONS = [
        'sales' => [
            'label' => 'Sales',
            'count_resource' => 'sales',
            'resources' => ['customers', 'products', 'variations', 'stocks', 'sales', 'sale_variation', 'sale_preparable_items'],
        ],
        'customers' => [
            'label' => 'Customers',
            'count_resource' => 'customers',
            'resources' => ['customers', 'transactions'],
        ],
        'payments' => [
            'label' => 'Payments',
            'count_resource' => 'payments',
            'resources' => ['payments', 'transactions'],
        ],
        'products' => [
            'label' => 'Products',
            'count_resource' => 'products',
            'resources' => ['products', 'product_attributes', 'variations', 'stocks', 'transactions'],
        ],
        'brands' => [
            'label' => 'Brands',
            'count_resource' => 'brands',
            'resources' => ['brands'],
        ],
        'categories' => [
            'label' => 'Categories',
            'count_resource' => 'categories',
            'resources' => ['categories'],
        ],
        'attributes' => [
            'label' => 'Attributes',
            'count_resource' => 'attributes',
            'resources' => ['attributes'],
        ],
        'units' => [
            'label' => 'Units',
            'count_resource' => 'units',
            'resources' => ['units', 'unit_dimensions'],
        ],
        'purchase_orders' => [
            'label' => 'Purchase Orders',
            'count_resource' => 'purchase_orders',
            'resources' => ['purchase_orders', 'purchase_order_products'],
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'count_resource' => 'suppliers',
            'resources' => ['suppliers', 'transactions'],
        ],
    ];

    private const NATURAL_UNIQUE_KEYS = [
        'customers' => ['store_id', 'phone'],
        'attributes' => ['store_id', 'name'],
        'store_settings' => ['store_id', 'key'],
        'role_has_permissions' => ['role_id', 'permission_id'],
        'user_role' => ['user_id', 'role_id', 'store_id'],
        'model_activities' => ['activityable_type', 'activityable_id'],
    ];

    private const FOREIGN_KEY_TABLE_MAP = [
        'store_id' => 'stores',
        'brand_id' => 'brands',
        'category_id' => 'categories',
        'attribute_id' => 'attributes',
        'product_id' => 'products',
        'variation_id' => 'variations',
        'preparable_variation_id' => 'variations',
        'stock_id' => 'stocks',
        'customer_id' => 'customers',
        'supplier_id' => 'suppliers',
        'purchase_order_id' => 'purchase_orders',
        'sale_id' => 'sales',
        'payment_id' => 'payments',
        'transaction_id' => 'transactions',
        'unit_id' => 'units',
        'dimension_id' => 'unit_dimensions',
        'base_unit_id' => 'units',
        'country_id' => 'countries',
        'currency_id' => 'currencies',
        'timezone_id' => 'timezones',
        'role_id' => 'roles',
        'permission_id' => 'permissions',
        'user_id' => 'users',
        'created_by' => 'users',
        'updated_by' => 'users',
    ];

    private const STORE_REGION_FOREIGN_KEYS = [
        'country_id',
        'currency_id',
        'timezone_id',
    ];

    private const CRITICAL_RECONCILE_MODULES = [
        'sales',
    ];

    private const MORPH_TYPE_MAP = [
        'App\\Models\\Variation' => ['class' => CoreVariation::class, 'table' => 'variations'],
        'SmartTill\\Core\\Models\\Variation' => ['class' => CoreVariation::class, 'table' => 'variations'],
        'App\\Models\\Customer' => ['class' => CoreCustomer::class, 'table' => 'customers'],
        'SmartTill\\Core\\Models\\Customer' => ['class' => CoreCustomer::class, 'table' => 'customers'],
        'App\\Models\\Supplier' => ['class' => CoreSupplier::class, 'table' => 'suppliers'],
        'SmartTill\\Core\\Models\\Supplier' => ['class' => CoreSupplier::class, 'table' => 'suppliers'],
        'App\\Models\\Sale' => ['class' => CoreSale::class, 'table' => 'sales'],
        'SmartTill\\Core\\Models\\Sale' => ['class' => CoreSale::class, 'table' => 'sales'],
        'App\\Models\\PurchaseOrder' => ['class' => CorePurchaseOrder::class, 'table' => 'purchase_orders'],
        'SmartTill\\Core\\Models\\PurchaseOrder' => ['class' => CorePurchaseOrder::class, 'table' => 'purchase_orders'],
        'App\\Models\\Payment' => ['class' => CorePayment::class, 'table' => 'payments'],
        'SmartTill\\Core\\Models\\Payment' => ['class' => CorePayment::class, 'table' => 'payments'],
        'App\\Models\\Product' => ['class' => CoreProduct::class, 'table' => 'products'],
        'SmartTill\\Core\\Models\\Product' => ['class' => CoreProduct::class, 'table' => 'products'],
    ];

    private array $nullableColumnCache = [];

    /**
     * Per-instance schema-introspection caches. Schema::hasTable /
     * hasColumn / getColumnListing each hit PRAGMA queries on SQLite —
     * calling them tens of thousands of times during bootstrap is one of
     * the dominant costs. Schema doesn't change at runtime, so caching the
     * answers for the lifetime of the service is safe.
     *
     * @var array<string, bool>
     */
    private array $hasTableCache = [];

    /** @var array<string, bool> */
    private array $hasColumnCache = [];

    /** @var array<string, list<string>> */
    private array $columnListingCache = [];

    private const PULL_ORDER = [
        'stores',
        'units',
        'unit_dimensions',
        'attributes',
        'brands',
        'categories',
        'products',
        'product_attributes',
        'variations',
        'stocks',
        'customers',
        'suppliers',
        'purchase_orders',
        'purchase_order_products',
        'sales',
        'sale_variation',
        'sale_preparable_items',
        'payments',
        'transactions',
        'store_settings',
        'images',
        'model_activities',
    ];

    private const ACCESS_CONTROL_ENTITIES = [
        'roles',
        'permissions',
        'role_has_permissions',
        'user_role',
        'invitations',
    ];

    public function getSyncModules(): array
    {
        return self::MODULE_DEFINITIONS;
    }

    /**
     * @return array<int, string>
     */
    public function getSyncModuleKeys(): array
    {
        return array_keys(self::MODULE_DEFINITIONS);
    }

    public function getModuleStats(?int $localStoreId): array
    {
        $storeId = (int) ($localStoreId ?? 0);
        $stats = [];

        foreach (self::MODULE_DEFINITIONS as $moduleKey => $definition) {
            $countTable = (string) ($definition['count_resource'] ?? (($definition['resources'][0] ?? '')));
            $total = 0;
            $synced = 0;
            $errors = 0;

            if ($countTable !== '' && Schema::hasTable($countTable)) {
                $countQuery = $this->applyStoreScope(DB::table($countTable), $countTable, $storeId);
                $total = (int) (clone $countQuery)->count();

                if (Schema::hasColumn($countTable, 'sync_state')) {
                    $synced = (int) (clone $countQuery)->where('sync_state', 'synced')->count();
                } elseif (Schema::hasColumn($countTable, 'synced_at')) {
                    $synced = (int) (clone $countQuery)->whereNotNull('synced_at')->count();
                }
            }

            foreach (($definition['resources'] ?? []) as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $baseQuery = $this->applyStoreScope(DB::table($table), $table, $storeId);
                if (Schema::hasColumn($table, 'sync_state')) {
                    $errors += (int) (clone $baseQuery)->where('sync_state', 'failed')->count();
                }

                if (Schema::hasColumn($table, 'sync_error')) {
                    $errors += (int) (clone $baseQuery)
                        ->whereNotNull('sync_error')
                        ->where('sync_error', '!=', '')
                        ->count();
                }
            }

            $stats[$moduleKey] = [
                'label' => (string) ($definition['label'] ?? $moduleKey),
                'total' => $total,
                'synced' => min($synced, $total),
                'errors' => $errors,
            ];
        }

        return $stats;
    }

    public function getSyncErrorOverview(?int $localStoreId): array
    {
        $storeId = (int) ($localStoreId ?? 0);
        $moduleErrors = collect(array_keys(self::MODULE_DEFINITIONS))
            ->mapWithKeys(fn (string $module): array => [$module => 0])
            ->all();

        if ($storeId <= 0) {
            return [
                'has_sync_errors' => false,
                'total_sync_errors' => 0,
                'module_errors' => $moduleErrors,
                'error_records' => [],
            ];
        }

        $moduleByResource = $this->moduleKeyByResource();
        $errorRecords = [];

        // Only iterate tables that we actually sync (PULL_ORDER) instead of every table
        // in the schema. The previous implementation called Schema::getTableListing()
        // followed by Schema::hasColumn() on each, which fires dozens of pragma_table_info
        // queries on every page load — devastating on Windows SQLite.
        foreach (self::PULL_ORDER as $listedTable) {
            $table = $this->normalizeListedTableName((string) $listedTable);
            if ($table === '') {
                continue;
            }

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sync_error')) {
                continue;
            }

            if (! $this->canScopeTableToStore($table)) {
                continue;
            }

            $query = $this->applyStoreScope(DB::table($table), $table, $storeId)
                ->whereNotNull('sync_error')
                ->where('sync_error', '!=', '');

            $count = (clone $query)->count();
            if ($count <= 0) {
                continue;
            }

            $moduleKey = $moduleByResource[$table] ?? null;
            if (is_string($moduleKey) && array_key_exists($moduleKey, $moduleErrors)) {
                $moduleErrors[$moduleKey] += (int) $count;
            }

            $rows = $query
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'server_id', 'sync_state', 'sync_error', 'updated_at']);

            foreach ($rows as $row) {
                $errorRecords[] = [
                    'module' => $moduleKey,
                    'table' => $table,
                    'local_id' => (int) ($row->id ?? 0),
                    'server_id' => is_numeric($row->server_id ?? null) ? (int) $row->server_id : null,
                    'sync_state' => (string) ($row->sync_state ?? ''),
                    'sync_error' => (string) ($row->sync_error ?? ''),
                    'updated_at' => (string) ($row->updated_at ?? ''),
                ];
            }
        }

        usort($errorRecords, fn (array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        $errorRecords = array_slice($errorRecords, 0, 50);
        $totalSyncErrors = array_sum($moduleErrors);

        return [
            'has_sync_errors' => $totalSyncErrors > 0,
            'total_sync_errors' => $totalSyncErrors,
            'module_errors' => $moduleErrors,
            'error_records' => $errorRecords,
        ];
    }

    public function getOutboxErrorOverviewForStore(int $localStoreId): array
    {
        if ($localStoreId <= 0) {
            return [
                'total_failed' => 0,
                'records' => [],
            ];
        }

        $records = [];
        $totalFailed = 0;

        SyncOutbox::query()
            ->where('status', 'failed')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($localStoreId, &$records, &$totalFailed): void {
                foreach ($rows as $row) {
                    if ($row->entity_type === 'cloud_store_sync') {
                        continue;
                    }

                    $belongsToStore = $this->syncOutboxRowBelongsToStore($row, $localStoreId);

                    if (! $belongsToStore) {
                        continue;
                    }

                    $totalFailed++;

                    if (count($records) >= 50) {
                        continue;
                    }

                    $records[] = [
                        'id' => (int) $row->id,
                        'entity_type' => (string) ($row->entity_type ?? ''),
                        'local_id' => (int) ($row->local_id ?? 0),
                        'operation' => (string) ($row->operation ?? ''),
                        'attempts' => (int) ($row->attempts ?? 0),
                        'error' => trim((string) ($row->error ?? '')) !== ''
                            ? (string) $row->error
                            : 'Failed without error details.',
                        'created_at' => (string) ($row->created_at ?? ''),
                    ];
                }
            });

        return [
            'total_failed' => $totalFailed,
            'records' => $records,
        ];
    }

    public function resetSyncFailuresForStore(int $localStoreId): void
    {
        if ($localStoreId <= 0) {
            return;
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->where('payload', 'like', '%SyncCloudStoreData%')
                ->delete();
        }

        SyncOutbox::query()
            ->where('status', 'failed')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($localStoreId): void {
                foreach ($rows as $row) {
                    if (
                        ($row->entity_type === 'cloud_store_sync' && (int) ($row->local_id ?? 0) === $localStoreId)
                        || $this->syncOutboxRowBelongsToStore($row, $localStoreId)
                    ) {
                        $row->status = 'pending';
                        $row->error = null;
                        $row->attempts = 0;
                        $row->save();
                    }
                }
            });

        // Iterate only known sync resources (see getSyncErrorOverview for rationale).
        foreach (self::PULL_ORDER as $listedTable) {
            $table = $this->normalizeListedTableName((string) $listedTable);
            if ($table === '' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sync_error') || ! $this->canScopeTableToStore($table)) {
                continue;
            }

            $query = $this->applyStoreScope(DB::table($table), $table, $localStoreId)
                ->whereNotNull('sync_error')
                ->where('sync_error', '!=', '');

            $updates = ['sync_error' => null];
            if (Schema::hasColumn($table, 'sync_state')) {
                $updates['sync_state'] = 'pending';
            }
            if (Schema::hasColumn($table, 'synced_at')) {
                $updates['synced_at'] = null;
            }

            $query->update($updates);
        }
    }

    public function resetSyncFailuresForStoreModule(int $localStoreId, string $module): void
    {
        if ($localStoreId <= 0) {
            return;
        }

        $resources = $this->resolveResourcesForModule($module);
        if ($resources === []) {
            return;
        }

        SyncOutbox::query()
            ->where('status', 'failed')
            ->whereIn('entity_type', $resources)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($localStoreId): void {
                foreach ($rows as $row) {
                    if ($this->syncOutboxRowBelongsToStore($row, $localStoreId)) {
                        $row->status = 'pending';
                        $row->error = null;
                        $row->attempts = 0;
                        $row->save();
                    }
                }
            });

        foreach ($resources as $table) {
            if ($table === '' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sync_error')) {
                continue;
            }

            if (! $this->canScopeTableToStore($table)) {
                continue;
            }

            $query = $this->applyStoreScope(DB::table($table), $table, $localStoreId)
                ->whereNotNull('sync_error')
                ->where('sync_error', '!=', '');

            $updates = ['sync_error' => null];
            if (Schema::hasColumn($table, 'sync_state')) {
                $updates['sync_state'] = 'pending';
            }
            if (Schema::hasColumn($table, 'synced_at')) {
                $updates['synced_at'] = null;
            }

            $query->update($updates);
        }
    }

    public function fetchStores(string $baseUrl, string $token): array
    {
        $response = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->get('/api/pos/stores');

        if (! $response->successful()) {
            $payload = $response->json();
            $message = is_array($payload)
                ? (string) ($payload['message'] ?? $payload['error'] ?? '')
                : '';

            if ($message === '') {
                $message = trim((string) $response->body());
            }

            if ($message === '') {
                $message = 'Unable to load stores from cloud server.';
            }

            throw new RuntimeException($message);
        }

        $payload = $response->json();

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        $stores = is_array($payload) ? ($payload['data'] ?? null) : null;

        if (is_array($stores) && array_is_list($stores)) {
            return $stores;
        }

        return [];
    }

    public function ensureLocalStoreFromServer(array $serverStore): ?Store
    {
        $serverId = $serverStore['id'] ?? null;
        if (! is_numeric($serverId)) {
            return null;
        }

        $resolvedRegion = $this->resolveLocalRegionReferences($serverStore);

        $attributes = [
            'name' => (string) ($serverStore['name'] ?? 'Store'),
            'country_id' => $resolvedRegion['country_id'],
            'currency_id' => $resolvedRegion['currency_id'],
            'timezone_id' => $resolvedRegion['timezone_id'],
            'server_id' => (int) $serverId,
            'sync_state' => 'synced',
            'synced_at' => now(),
            'sync_error' => null,
        ];

        return Store::query()->updateOrCreate(
            ['server_id' => (int) $serverId],
            $attributes
        );
    }

    public function ensureLocalStoresFromServer(array $serverStores): array
    {
        $localStores = [];

        foreach ($serverStores as $serverStore) {
            if (! is_array($serverStore)) {
                continue;
            }

            $localStore = $this->ensureLocalStoreFromServer($serverStore);
            if ($localStore) {
                $localStores[] = $localStore;
            }
        }

        return $localStores;
    }

    public function syncNow(string $baseUrl, string $token, Store $localStore, ?string $module = null): array
    {
        $runtimeStateService = app(RuntimeStateService::class);

        if (
            (int) ($runtimeStateService->get()->active_store_id ?? 0) === (int) $localStore->id
            && ! $runtimeStateService->isStoreBootstrapped((int) $localStore->id)
        ) {
            // Kill switch: when CLOUD_BOOTSTRAP_USE_SNAPSHOT=false, skip the
            // fast path entirely. Useful if a snapshot install ever misbehaves
            // in production and we need to disable it remotely without a
            // re-release.
            if ((bool) config('pos.bootstrap.use_snapshot', true)) {
                // Try the fast snapshot path first; fall back to the legacy
                // ndjson bootstrap when the server is on an older build, or
                // when the snapshot import fails for any reason. A working
                // device must never be stranded.
                $snapshot = $this->runSnapshotBootstrap($baseUrl, $token, $localStore);
                if (($snapshot['fallback'] ?? null) !== 'ndjson') {
                    return $snapshot;
                }
            }

            return $this->runBootstrapSync($baseUrl, $token, $localStore);
        }

        return $this->runDeltaSync($baseUrl, $token, $localStore, $module);
    }

    /**
     * Fast-path bootstrap: download a server-rendered, gzipped SQL snapshot
     * and execute it inside a single SQLite transaction.
     *
     * Why this is dramatically faster than runBootstrapSync (ndjson):
     *   - One HTTP roundtrip vs three (manifest + status poll + download).
     *   - Server already aligned every server_id locally — no FK remap.
     *   - Server set sync_state='synced' on every row — no observer storm.
     *   - One SQLite COMMIT vs hundreds (one per chunk in the ndjson path).
     *   - Gzip compresses the wire payload 5–10x.
     *
     * Returns ['ok' => false, 'fallback' => 'ndjson'] on 404/422/import error
     * so the caller can fall back to runBootstrapSync without disturbing
     * the user. A working device must never get stranded by a snapshot bug.
     */
    public function runSnapshotBootstrap(string $baseUrl, string $token, Store $localStore): array
    {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        if (! $this->hasValidCloudToken($baseUrl, $token)) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        $runtimeStateService = app(RuntimeStateService::class);
        // Path-specific label so the bootstrap UI surfaces which mechanism
        // is actually running. If the user sees "Full bootstrap (legacy
        // path)" later in the same install, they know snapshot fell back.
        $runtimeStateService->markBootstrapStarted((int) $localStore->id, 'snapshot-'.now()->format('YmdHis'), 'Snapshot bootstrap — requesting store data');

        // Tell the server which columns we have per table. The server emits
        // INSERT statements containing only those columns, with sync
        // housekeeping defaults filled in (server_id, sync_state='synced',
        // synced_at=now, local_id/sync_error=NULL).
        $payloadTables = $this->buildSnapshotTablePayload();

        $directory = storage_path('app/cloud-sync');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $snapshotPath = $directory."/snapshot-store-{$localStore->id}-".now()->format('YmdHis').'.sql.gz';

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(30)
                ->timeout(0)
                ->sink($snapshotPath)
                ->post("/api/pos/v2/stores/{$serverStoreId}/snapshot", [
                    'tables' => $payloadTables,
                ]);
        } catch (\Throwable $exception) {
            $runtimeStateService->markBootstrapFailed((int) $localStore->id, 'Snapshot download failed.');
            if (file_exists($snapshotPath)) {
                @unlink($snapshotPath);
            }
            Log::warning('cloud-sync: snapshot download threw, falling back to ndjson', [
                'store_id' => $localStore->id,
                'exception' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'message' => $exception->getMessage(), 'fallback' => 'ndjson'];
        }

        if (! $response->successful()) {
            if (file_exists($snapshotPath)) {
                @unlink($snapshotPath);
            }
            $status = (int) $response->status();
            $message = (string) ($response->json('message') ?? 'Unable to download store snapshot.');

            // 404 = snapshot endpoint not deployed on this server version.
            // 422 = validation failure (rare). Either way: fall back gracefully.
            if (in_array($status, [404, 422], true)) {
                Log::warning('cloud-sync: snapshot endpoint returned non-ok, falling back to ndjson', [
                    'store_id' => $localStore->id,
                    'http_status' => $status,
                    'server_message' => $message,
                ]);

                return ['ok' => false, 'fallback' => 'ndjson', 'message' => $message];
            }

            $runtimeStateService->markBootstrapFailed((int) $localStore->id, $message);
            Log::error('cloud-sync: snapshot endpoint hard-failed', [
                'store_id' => $localStore->id,
                'http_status' => $status,
                'server_message' => $message,
            ]);

            return ['ok' => false, 'message' => $message];
        }

        // Some HTTP backends (notably Http::fake under test) ignore the
        // sink() target. Make sure the file ends up populated either way
        // by writing the response body if the sink didn't.
        if (! is_file($snapshotPath) || filesize($snapshotPath) === 0) {
            $body = (string) $response->body();
            if ($body !== '') {
                file_put_contents($snapshotPath, $body);
            }
        }

        $runtimeStateService->updateBootstrapProgress((int) $localStore->id, 40, 'Snapshot downloaded — installing (fast path)');

        try {
            $rowsApplied = $this->installSnapshotFromFile($snapshotPath, (int) $localStore->id);
            $runtimeStateService->markBootstrapReady((int) $localStore->id);

            return [
                'ok' => true,
                'mode' => 'snapshot',
                'installed' => $rowsApplied,
            ];
        } catch (\Throwable $throwable) {
            // Import failure: rollback already happened (we wrap in
            // DB::transaction). Fall back to the ndjson path so the user
            // gets SOMETHING rather than a dead screen.
            $runtimeStateService->markBootstrapFailed((int) $localStore->id, 'Snapshot install failed; falling back to row-by-row bootstrap.');
            Log::error('cloud-sync: snapshot import threw, falling back to ndjson', [
                'store_id' => $localStore->id,
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return ['ok' => false, 'fallback' => 'ndjson', 'message' => $throwable->getMessage()];
        } finally {
            if (file_exists($snapshotPath)) {
                @unlink($snapshotPath);
            }
        }
    }

    /**
     * Build the `tables` payload describing which columns the POS has for
     * every syncable table. The server uses this to emit only INSERT
     * statements compatible with the local schema.
     *
     * @return array<string, list<string>>
     */
    private function buildSnapshotTablePayload(): array
    {
        $payload = [];

        foreach (self::PULL_ORDER as $table) {
            if (! $this->cachedHasTable($table)) {
                continue;
            }
            $payload[$table] = array_values(array_unique($this->cachedColumnListing($table)));
        }

        return $payload;
    }

    /**
     * Decompress and execute the SQL dump as a single SQLite transaction.
     *
     * Hardened against the four risks discovered during review:
     *
     *  1. Memory — uses streaming `gzread` + bounded buffer flushes at
     *     statement boundaries, instead of loading the entire decompressed
     *     SQL into PHP memory. Buffer cap is 8 MB regardless of dump size.
     *
     *  2. ID collisions — wipes every syncable table BEFORE importing so
     *     no stale rows from other stores can collide with the snapshot's
     *     server-assigned ids. The user opted into "single-store at a time"
     *     behaviour; the local DB ends up containing exactly the snapshot.
     *
     *  3. Concurrent writers — takes a Cache lock keyed on the store so
     *     observers/push/auto-sync workers don't deadlock with our long-
     *     running transaction.
     *
     *  4. Long-running transaction — the wipe + import + sequence reset
     *     all live inside ONE transaction so a crash mid-import leaves the
     *     device with its previous data intact, not half-wiped.
     *
     * Returns approximate row count (sum of synced rows across imported tables).
     */
    private function installSnapshotFromFile(string $snapshotPath, int $localStoreId): int
    {
        if (! is_file($snapshotPath)) {
            throw new RuntimeException('Snapshot file is missing.');
        }

        $lock = Cache::lock("snapshot-bootstrap-{$localStoreId}", 1800);
        if (! $lock->get()) {
            throw new RuntimeException('Another snapshot install is already in progress for this store.');
        }

        try {
            $handle = gzopen($snapshotPath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open snapshot file.');
            }

            // Capture the local store's region FK ids BEFORE the import so
            // we can restore them after. POS's countries/currencies/timezones
            // tables have their OWN auto-increment ids that don't match the
            // server's; the snapshot's INSERT OR REPLACE on stores writes
            // SERVER's region ids verbatim, which on POS point at the wrong
            // rows (e.g., server's currency_id=50 = PKR but POS's id=50 =
            // RUB). The login flow (ensureLocalStoreFromServer) resolved
            // these correctly by code/name when the store was first created
            // — we just need to keep that mapping safe through the import.
            $preservedRegion = DB::table('stores')
                ->where('id', $localStoreId)
                ->select('country_id', 'currency_id', 'timezone_id')
                ->first();

            // CRITICAL: turn FK enforcement OFF *outside* the transaction.
            // `PRAGMA foreign_keys` is a no-op inside a transaction in
            // SQLite — it must be issued when no transaction is open.
            // `PRAGMA defer_foreign_keys` alone is not enough: it only
            // delays the check until COMMIT, where it STILL throws if any
            // ref is genuinely orphaned (e.g., a row in the dump points at
            // an id that ends up never inserted because of ordering or a
            // server-side data drift).
            //
            // The dump is server-authoritative for the entire store and
            // we wipe-then-import inside one transaction, so disabling FK
            // checks for the import window is safe. We restore enforcement
            // immediately after.
            DB::statement('PRAGMA foreign_keys = OFF');

            try {
                DB::transaction(function () use ($handle): void {
                    // Wipe FIRST so the snapshot is authoritative. The
                    // single-store-at-a-time model means the local DB
                    // contains exactly the snapshot's data once we're done.
                    // No risk of locally-created rows with the same id as
                    // an incoming server row clobbering each other.
                    $this->wipeAllSyncableTables();

                    $buffer = '';
                    $bufferLimitBytes = 8 * 1024 * 1024; // 8 MB

                    while (! gzeof($handle)) {
                        $chunk = gzread($handle, 65536);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $buffer .= $chunk;

                        // Flush at a statement boundary (";\n") once the
                        // buffer crosses the size limit. Keeping flushes
                        // aligned to statement boundaries means PDO::exec
                        // always sees syntactically complete SQL.
                        if (strlen($buffer) >= $bufferLimitBytes) {
                            $lastBoundary = strrpos($buffer, ";\n");
                            if ($lastBoundary !== false) {
                                $toExec = substr($buffer, 0, $lastBoundary + 2);
                                $buffer = substr($buffer, $lastBoundary + 2);
                                $this->execSnapshotChunk($toExec);
                            }
                        }
                    }

                    // Drain the tail.
                    if (trim($buffer) !== '') {
                        $this->execSnapshotChunk($buffer);
                    }
                });
            } finally {
                gzclose($handle);
                // Always restore FK enforcement — even on import failure —
                // so subsequent local writes still get the safety net.
                DB::statement('PRAGMA foreign_keys = ON');
            }

            // sqlite_sequence reset happens OUTSIDE the import transaction
            // because INSERT-OR-REPLACE on sqlite_sequence is a system
            // table write that's cleaner to do as its own step.
            $this->resetSqliteSequenceForBootstrappedTables();

            // Restore the previously-resolved local region FK ids on the
            // store row so the user keeps seeing the right currency name,
            // timezone, etc. (See the comment near the capture above for
            // why this is necessary.) Only restore non-null values — if
            // the store had never been set up locally we leave whatever
            // the server sent and accept the user may need to re-pick.
            if ($preservedRegion !== null) {
                $regionUpdates = array_filter([
                    'country_id' => $preservedRegion->country_id ?? null,
                    'currency_id' => $preservedRegion->currency_id ?? null,
                    'timezone_id' => $preservedRegion->timezone_id ?? null,
                ], fn ($value): bool => $value !== null);

                if ($regionUpdates !== []) {
                    DB::table('stores')->where('id', $localStoreId)->update($regionUpdates);
                }
            }
        } finally {
            $lock->release();
        }

        $total = 0;
        foreach (self::PULL_ORDER as $table) {
            if ($this->cachedHasTable($table) && $this->cachedHasColumn($table, 'sync_state')) {
                $total += (int) DB::table($table)->where('sync_state', 'synced')->count();
            }
        }

        return $total;
    }

    /**
     * Execute one buffered chunk of snapshot SQL. Per-chunk strip of
     * BEGIN/COMMIT/PRAGMA foreign_keys means each chunk is safe to exec
     * inside our outer DB::transaction.
     */
    private function execSnapshotChunk(string $sql): void
    {
        $sql = preg_replace('/^\s*(BEGIN|COMMIT)\s*;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*PRAGMA\s+foreign_keys\s*=\s*(ON|OFF)\s*;\s*$/mi', '', $sql) ?? $sql;

        if (trim($sql) === '') {
            return;
        }

        DB::unprepared($sql);
    }

    /**
     * Wipe every syncable table EXCEPT `stores` (which holds the registry
     * mapping local store id ↔ server id and must survive). Reverse
     * PULL_ORDER so child tables clear before their parents — keeps any
     * engine with eager FK checking happy.
     */
    private function wipeAllSyncableTables(): void
    {
        foreach (array_reverse(self::PULL_ORDER) as $table) {
            if ($table === 'stores') {
                continue;
            }
            if (! $this->cachedHasTable($table)) {
                continue;
            }
            DB::table($table)->delete();
        }
    }

    /**
     * After importing rows with explicit ids, sqlite_sequence still thinks
     * autoincrement starts at 0 — the next local insert would collide. Set
     * each table's sequence to MAX(id) so subsequent inserts pick up at
     * MAX(id)+1.
     */
    private function resetSqliteSequenceForBootstrappedTables(): void
    {
        if (! $this->cachedHasTable('sqlite_sequence')) {
            return;
        }

        foreach (self::PULL_ORDER as $table) {
            if (! $this->cachedHasTable($table) || ! $this->cachedHasColumn($table, 'id')) {
                continue;
            }

            $maxId = (int) DB::table($table)->max('id');
            if ($maxId <= 0) {
                continue;
            }

            // Use INSERT-OR-REPLACE on sqlite_sequence — the standard
            // SQLite-recommended way to set the next autoincrement value.
            DB::statement('INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $maxId]);
        }
    }

    public function runBootstrapSync(string $baseUrl, string $token, Store $localStore): array
    {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        if (! $this->hasValidCloudToken($baseUrl, $token)) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        $runtimeStateService = app(RuntimeStateService::class);

        $bootstrapResponse = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->post("/api/pos/v2/stores/{$serverStoreId}/bootstrap");

        if (! $bootstrapResponse->successful()) {
            return ['ok' => false, 'message' => (string) ($bootstrapResponse->json('message') ?? $bootstrapResponse->body() ?: 'Unable to prepare store bootstrap.')];
        }

        $generation = (string) ($bootstrapResponse->json('generation') ?? '');
        $manifest = (array) ($bootstrapResponse->json('manifest') ?? []);
        // Mark this run as the legacy ndjson path so the UI can show which
        // mechanism is in use. If the user sees this label, snapshot fell
        // back — check storage/logs/laravel.log for "cloud-sync: snapshot"
        // entries to learn why.
        $runtimeStateService->markBootstrapStarted((int) $localStore->id, $generation, 'Legacy bootstrap — downloading store data');

        // No total-time cap on the bootstrap download — the snapshot can be
        // arbitrarily large and we cannot predict a safe upper bound for the
        // user's connection speed. Guzzle/cURL treats 0 as "wait forever for
        // the body". A 30s connect timeout is still enforced so a dead host
        // fails fast instead of hanging silently.
        $downloadResponse = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->accept('application/x-ndjson')
            ->connectTimeout(30)
            ->timeout(0)
            ->get("/api/pos/v2/stores/{$serverStoreId}/bootstrap/{$generation}/download");

        if (! $downloadResponse->successful()) {
            $runtimeStateService->markBootstrapFailed((int) $localStore->id, 'Unable to download store data.');

            return ['ok' => false, 'message' => (string) ($downloadResponse->json('message') ?? $downloadResponse->body() ?: 'Unable to download store bootstrap.')];
        }

        $runtimeStateService->updateBootstrapProgress((int) $localStore->id, 40, 'Store data downloaded');

        $directory = storage_path('app/cloud-sync');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $snapshotPath = $directory."/bootstrap-store-{$localStore->id}-{$generation}.ndjson";
        file_put_contents($snapshotPath, $downloadResponse->body());

        try {
            $runtimeStateService->markBootstrapInstalling((int) $localStore->id);
            $installed = $this->installBootstrapSnapshot($snapshotPath, (int) $localStore->id, $manifest);
            $reconciled = $this->reconcileBootstrapResources($baseUrl, $token, $serverStoreId, (int) $localStore->id, $manifest);
            $runtimeStateService->markBootstrapReady((int) $localStore->id);

            return [
                'ok' => true,
                'mode' => 'bootstrap',
                'installed' => $installed,
                'reconciled' => $reconciled,
            ];
        } catch (\Throwable $throwable) {
            $runtimeStateService->markBootstrapFailed((int) $localStore->id, $throwable->getMessage());

            return [
                'ok' => false,
                'message' => $throwable->getMessage(),
            ];
        } finally {
            if (is_file($snapshotPath)) {
                @unlink($snapshotPath);
            }
        }
    }

    /**
     * Push-only sync: ships pending local rows to the cloud and stops. No
     * GET /delta, no ack roundtrip, no big incoming payload. Used by the
     * model observer so a single sale save propagates in ~1 second instead
     * of waiting for a full delta cycle (which can take 10–60s when the
     * server returns a lot of incoming data).
     */
    /**
     * @param  int|null  $maxSecondsBudget  optional wall-clock cap. When set,
     *                                      the push loop checks elapsed time between resource batches
     *                                      and bails early once exceeded. Used by the store-switch
     *                                      middleware to prevent a slow/offline server from freezing
     *                                      the Filament UI on switch.
     */
    public function runPushOnly(
        string $baseUrl,
        string $token,
        Store $localStore,
        ?string $module = null,
        ?string $resource = null,
        ?int $maxSecondsBudget = null,
    ): array {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        $deadline = $maxSecondsBudget !== null ? microtime(true) + $maxSecondsBudget : null;

        if (! $this->hasValidCloudToken($baseUrl, $token)) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        $resources = $resource !== null
            ? [$resource]
            : ($module !== null ? $this->resolveResourcesForModule($module) : self::PULL_ORDER);

        if ($module !== null && $resources === []) {
            return ['ok' => false, 'message' => 'Invalid sync module selected.'];
        }

        $pushed = $this->pushPendingRowsV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources, $deadline);
        $tombstoned = $this->pushTombstonesV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources, $deadline);

        $timedOut = $deadline !== null && microtime(true) >= $deadline;

        return [
            'ok' => true,
            'mode' => 'push',
            'module' => $module,
            'resource' => $resource,
            'pushed' => $pushed,
            'tombstoned' => $tombstoned,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * One-shot repair for an existing install whose local data drifted from
     * the cloud (line items dropped, pushes silenced, etc.).
     *
     * Three phases:
     *
     *   1. Replay parked inbound rows + push everything the device still
     *      owes the cloud (pending/failed rows + tombstones). This MUST
     *      complete cleanly — if any row still can't push, we abort
     *      before the destructive snapshot wipe so unpushed local edits
     *      are never lost. The user is asked to resolve the push errors
     *      and retry.
     *
     *   2. Snapshot bootstrap — re-download the server's complete
     *      authoritative view as a gzipped SQL dump and INSERT OR REPLACE
     *      every row. 10–50× faster than the legacy row-by-row pull and
     *      semantically equivalent now that Phase 1 guarantees the server
     *      has every local edit. Falls back to the legacy ndjson pull if
     *      the snapshot endpoint is unavailable or import fails — so
     *      older servers and edge cases still work, just slower.
     *
     *   3. Reset the delta cursor so the next regular sync starts from
     *      now, not from a stale "last successful delta" timestamp that
     *      might miss server-side rows that arrived during the repair.
     *
     * Safe to run multiple times — the underlying upserts are idempotent.
     */
    public function runForceReconcile(string $baseUrl, string $token, Store $localStore): array
    {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        if (! $this->hasValidCloudToken($baseUrl, $token)) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        $localStoreId = (int) $localStore->id;

        // Phase 0: replay any previously parked inbound rows in case the
        // missing dependency has since landed.
        $this->processPendingInboundRows($localStoreId);

        // Phase 1: push everything pending. We don't supply a deadline —
        // repair sync is user-initiated and the user is waiting; we want
        // every row to either succeed or surface a clear error.
        $pushed = $this->pushPendingRowsV2($baseUrl, $token, $serverStoreId, $localStoreId, self::PULL_ORDER);
        $this->pushTombstonesV2($baseUrl, $token, $serverStoreId, $localStoreId, self::PULL_ORDER);

        // SAFETY CHECK: if any rows remain `sync_state=pending` or
        // `sync_state=failed` after Phase 1, the server hasn't accepted
        // them yet. We must NOT proceed to the snapshot wipe — it would
        // destroy unpushed local edits. Ask the user to resolve the push
        // errors first and try again.
        $unpushedCount = $this->countUnpushedLocalRows($localStoreId);
        if ($unpushedCount > 0) {
            return [
                'ok' => false,
                'mode' => 'reconcile',
                'pushed' => $pushed,
                'message' => "Unable to repair: {$unpushedCount} local row(s) could not be pushed to the cloud. Resolve the sync errors (visible in the sync status panel) and try again.",
                'unpushed' => $unpushedCount,
            ];
        }

        // Phase 2: snapshot bootstrap — the fast path. Phase 1 guarantees
        // server has every local edit, so wiping + reimporting is safe.
        $snapshotResult = $this->runSnapshotBootstrap($baseUrl, $token, $localStore);

        if (($snapshotResult['ok'] ?? false) === true) {
            $pulled = (int) ($snapshotResult['installed'] ?? 0);
            $reconcileMode = 'reconcile-snapshot';
        } else {
            // Fallback: snapshot endpoint unavailable (older server) or
            // the import threw. Use the legacy slow-but-reliable path so
            // repair still completes.
            $pulled = $this->pullAllResources($baseUrl, $token, $serverStoreId, $localStoreId, self::PULL_ORDER);
            $reconcileMode = 'reconcile-ndjson';
            Log::warning('cloud-sync: repair snapshot failed, used legacy ndjson fallback', [
                'store_id' => $localStoreId,
                'snapshot_message' => (string) ($snapshotResult['message'] ?? ''),
            ]);
        }

        // Phase 3: reset the delta cursor.
        $runtimeStateService = app(RuntimeStateService::class);
        $runtimeStateService->updateStoreSyncState($localStoreId, [
            'last_delta_pull_at' => now()->toDateTimeString(),
        ]);
        $runtimeStateService->touchLastSynced();

        return [
            'ok' => true,
            'mode' => $reconcileMode,
            'pushed' => $pushed,
            'pulled' => $pulled,
        ];
    }

    /**
     * Count rows across every syncable table that the device still owes
     * the cloud (sync_state in pending/failed). Used by repair sync as
     * the safety check before the destructive snapshot wipe.
     */
    private function countUnpushedLocalRows(int $localStoreId): int
    {
        $total = 0;

        foreach (self::PULL_ORDER as $table) {
            if (! $this->cachedHasTable($table) || ! $this->cachedHasColumn($table, 'sync_state')) {
                continue;
            }

            $total += (int) $this->applyStoreScope(DB::table($table), $table, $localStoreId)
                ->whereIn('sync_state', ['pending', 'failed'])
                ->count();
        }

        // Also count tombstones still pending in sync_outbox — they're a
        // separate flavour of "owed to cloud" but equally destructive
        // to wipe before they ship.
        if (Schema::hasTable('sync_outbox')) {
            $total += (int) DB::table('sync_outbox')
                ->where('operation', 'delete')
                ->whereIn('status', ['pending', 'failed'])
                ->count();
        }

        return $total;
    }

    public function runDeltaSync(
        string $baseUrl,
        string $token,
        Store $localStore,
        ?string $module = null,
        ?string $resource = null,
    ): array {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        if (! $this->hasValidCloudToken($baseUrl, $token)) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        // Replay any inbound rows that previous syncs couldn't resolve yet
        // (e.g. sale_variation rows arrived before their parent variation).
        // Doing this BEFORE the fresh delta means new resources we're about
        // to pull can satisfy the missing dependencies of stale parked rows.
        $this->processPendingInboundRows((int) $localStore->id);

        $resources = $resource !== null
            ? [$resource]
            : ($module !== null ? $this->resolveResourcesForModule($module) : self::PULL_ORDER);

        if ($module !== null && $resources === []) {
            return ['ok' => false, 'message' => 'Invalid sync module selected.'];
        }

        $hasPendingLocalRows = $this->hasPendingRowsForResources((int) $localStore->id, $resources);

        $runtimeStateService = app(RuntimeStateService::class);
        $runtimeStateService->markDeltaSyncing((int) $localStore->id, 'Pulling cloud updates');

        $storeSyncState = $runtimeStateService->getStoreSyncState((int) $localStore->id);
        $since = $storeSyncState['last_delta_pull_at'] ?? null;

        // CRITICAL: the server caps each resource at 500 rows/page and uses
        // `meta.has_more` + per-resource `cursor` to signal more available.
        // Before this loop, the POS made ONE /delta call and discarded the
        // cursor → any row past the cap was permanently lost because the next
        // sync's `since` had advanced past it. We now loop until the server
        // reports has_more=false, advancing the cursor by the OLDEST resource
        // cursor each iteration (safe: idempotent upserts re-process the
        // already-pulled rows for resources that finished early).
        $cursorSince = is_string($since) && $since !== '' ? $since : null;
        $cursorSinceId = 0;
        $pulled = 0;
        $maxIterations = 50; // safety net against any server-side loop bug
        $iterations = 0;
        $newCursorSince = $cursorSince;
        $newCursorSinceId = $cursorSinceId;

        do {
            $iterations++;

            $deltaResponse = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout(0)
                ->get("/api/pos/v2/stores/{$serverStoreId}/delta", array_filter([
                    'since' => $cursorSince,
                    'since_id' => $cursorSinceId > 0 ? $cursorSinceId : null,
                    'resource' => $resource,
                ], fn ($value) => $value !== null));

            if (! $deltaResponse->successful()) {
                $status = (int) $deltaResponse->status();
                $message = (string) ($deltaResponse->json('message') ?? $deltaResponse->body() ?: 'Unable to pull delta updates.');

                if (in_array($status, [404, 422], true)) {
                    $pushed = $this->pushPendingRows($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
                    $pulled = $this->pullAllResources($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
                    $runtimeStateService->markDeltaCompleted((int) $localStore->id);

                    return [
                        'ok' => true,
                        'mode' => 'delta',
                        'module' => $module,
                        'resource' => $resource,
                        'pulled' => $pulled,
                        'pushed' => $pushed,
                        'fallback' => 'v1',
                    ];
                }

                return ['ok' => false, 'message' => $message];
            }

            $deltaPayload = $deltaResponse->json();
            if (! is_array($deltaPayload) || ! array_key_exists('data', $deltaPayload)) {
                $pushed = $this->pushPendingRows($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
                $pulled = $this->pullAllResources($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
                $runtimeStateService->markDeltaCompleted((int) $localStore->id);

                return [
                    'ok' => true,
                    'mode' => 'delta',
                    'module' => $module,
                    'resource' => $resource,
                    'pulled' => $pulled,
                    'pushed' => $pushed,
                    'fallback' => 'v1',
                ];
            }

            $resourcesPayload = (array) ($deltaPayload['data'] ?? []);
            $pulled += $this->applyDeltaResources($resourcesPayload, (int) $localStore->id);

            $hasMore = (bool) ($deltaPayload['meta']['has_more'] ?? false);

            // Advance the cursor to the OLDEST resource cursor returned in
            // this page so any resource that ran short still gets fully
            // pulled on the next iteration. We track the LATEST cursor seen
            // for persistence as the new `last_delta_pull_at`.
            ['next_since' => $nextCursorSince, 'next_since_id' => $nextCursorSinceId, 'max_since' => $maxSeenSince, 'max_since_id' => $maxSeenSinceId]
                = $this->resolveDeltaPaginationCursors($resourcesPayload);

            if ($maxSeenSince !== null) {
                $newCursorSince = $maxSeenSince;
                $newCursorSinceId = $maxSeenSinceId;
            }

            if (! $hasMore) {
                break;
            }

            // No forward progress (cursor unchanged) → bail to avoid infinite
            // loop on a misbehaving server.
            if ($nextCursorSince === $cursorSince && $nextCursorSinceId === $cursorSinceId) {
                break;
            }

            $cursorSince = $nextCursorSince ?? $cursorSince;
            $cursorSinceId = $nextCursorSinceId;
        } while ($iterations < $maxIterations);

        $runtimeStateService->markDeltaPulled((int) $localStore->id);

        $pushed = $this->pushPendingRowsV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
        // Flush tombstones too — without this, locally-deleted rows would
        // only reach the server on the next manual reconcile.
        $this->pushTombstonesV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
        $backfilled = 0;

        if (
            $module !== null
            && in_array($module, self::CRITICAL_RECONCILE_MODULES, true)
            && ! $hasPendingLocalRows
        ) {
            $runtimeStateService->updateStoreSyncState((int) $localStore->id, [
                'bootstrap_progress_label' => 'Backfilling full sales data',
            ]);
            $backfilled = $this->pullAllResources($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
        }

        Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->post("/api/pos/v2/stores/{$serverStoreId}/delta/ack", [
                'resources' => $resources,
                'pulled' => $pulled,
                'pushed' => $pushed,
            ]);

        $runtimeStateService->markDeltaCompleted((int) $localStore->id);

        // Persist the newest cursor we actually saw AFTER markDeltaCompleted
        // (which resets last_delta_pull_at to now()). This is the value the
        // NEXT sync uses as `since` — anchoring it to the server's most
        // recent updated_at means we don't re-walk rows we already pulled,
        // but also don't accidentally skip rows whose updated_at sits
        // between the server's cursor and "now" on this device's clock.
        if ($newCursorSince !== null) {
            $runtimeStateService->updateStoreSyncState((int) $localStore->id, [
                'last_delta_pull_at' => $newCursorSince,
            ]);
        }

        return [
            'ok' => true,
            'mode' => 'delta',
            'module' => $module,
            'resource' => $resource,
            'pulled' => $pulled,
            'pushed' => $pushed,
            'backfilled' => $backfilled,
        ];
    }

    public function syncChunk(
        string $baseUrl,
        string $token,
        Store $localStore,
        ?string $module = null,
        ?string $resource = null,
        int $page = 1
    ): array {
        $serverStoreId = (int) ($localStore->server_id ?? 0);
        if ($serverStoreId <= 0) {
            return ['ok' => false, 'message' => 'Local store is not linked with cloud store.'];
        }

        $authCheck = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->get('/api/pos/user');

        if (! $authCheck->successful()) {
            return ['ok' => false, 'message' => 'Cloud token is invalid or expired.'];
        }

        $resources = $module !== null
            ? $this->resolveResourcesForModule($module)
            : self::PULL_ORDER;

        if ($module !== null && $resources === []) {
            return ['ok' => false, 'message' => 'Invalid sync module selected.'];
        }

        $activeResource = $resource;
        if ($activeResource === null) {
            $activeResource = $resources[0] ?? null;
        }

        if ($activeResource === null || ! in_array($activeResource, $resources, true)) {
            return [
                'ok' => true,
                'module' => $module,
                'pushed' => 0,
                'pulled' => 0,
                'next' => null,
            ];
        }

        $pushed = 0;
        if ((int) $page === 1) {
            $pushed = $this->pushPendingRows($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
        }

        $pullChunk = $this->pullResourceChunk(
            $baseUrl,
            $token,
            $serverStoreId,
            (int) $localStore->id,
            $resources,
            $activeResource,
            max(1, (int) $page),
            self::MAX_PAGES_PER_CHUNK
        );

        if (($pullChunk['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'message' => (string) ($pullChunk['message'] ?? 'Unable to sync cloud data chunk.'),
            ];
        }

        $next = null;
        $nextResource = $pullChunk['next_resource'] ?? null;
        $nextPage = (int) ($pullChunk['next_page'] ?? 0);

        if (is_string($nextResource) && $nextResource !== '' && $nextPage > 0) {
            $next = [
                'resource' => $nextResource,
                'page' => $nextPage,
            ];
        }

        return [
            'ok' => true,
            'module' => $module,
            'pushed' => $pushed,
            'pulled' => (int) ($pullChunk['pulled'] ?? 0),
            'next' => $next,
        ];
    }

    private function pushPendingRows(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = []): int
    {
        $pushedCount = $this->pushPendingTableRows($baseUrl, $token, $serverStoreId, $localStoreId, $resources);

        $pendingQuery = SyncOutbox::query()
            ->where('status', 'pending')
            ->whereIn('entity_type', array_values(array_unique([
                ...self::PULL_ORDER,
                ...self::ACCESS_CONTROL_ENTITIES,
            ])))
            ->orderBy('id')
            ->limit(100);

        if (! empty($resources)) {
            $pendingQuery->whereIn('entity_type', array_values(array_unique([
                ...$resources,
                ...self::ACCESS_CONTROL_ENTITIES,
            ])));
        }

        $pending = $pendingQuery->get();

        foreach ($pending as $row) {
            if (in_array($row->entity_type, self::ACCESS_CONTROL_ENTITIES, true)) {
                $row->status = 'synced';
                $row->error = 'Skipped in POS: access-control entities are server-managed.';
                $row->attempts = (int) $row->attempts + 1;
                $row->save();

                continue;
            }

            if (! $this->syncOutboxRowBelongsToStore($row, $localStoreId)) {
                continue;
            }

            $payload = json_decode((string) $row->payload, true) ?: [];

            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->post("/api/pos/v1/stores/{$serverStoreId}/sync/{$row->entity_type}/upsert", [
                    'rows' => [$payload],
                ]);

            if ($response->successful()) {
                $row->status = 'synced';
                $row->error = null;
                $row->attempts = (int) $row->attempts + 1;
                $row->save();
                $pushedCount++;
            } else {
                $row->status = 'failed';
                $row->error = (string) ($response->json('message') ?? $response->body());
                $row->attempts = (int) $row->attempts + 1;
                $row->save();
            }
        }

        return $pushedCount;
    }

    private function pushPendingTableRows(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = []): int
    {
        $resourceList = ! empty($resources) ? $resources : self::PULL_ORDER;
        $pushedCount = 0;

        foreach ($resourceList as $resource) {
            if (! Schema::hasTable($resource) || ! Schema::hasColumn($resource, 'sync_state')) {
                continue;
            }

            if (in_array($resource, self::ACCESS_CONTROL_ENTITIES, true)) {
                continue;
            }

            $rows = $this->applyStoreScope(DB::table($resource), $resource, $localStoreId)
                ->whereIn('sync_state', ['pending', 'failed'])
                ->tap(fn (Builder $query) => $this->applyDefaultOrder($query, $resource))
                ->limit(200)
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            if (in_array($resource, ['sale_variation', 'sale_preparable_items'], true)) {
                $saleIds = $rows
                    ->pluck('sale_id')
                    ->filter(fn ($saleId): bool => is_numeric($saleId))
                    ->map(fn ($saleId): int => (int) $saleId)
                    ->unique()
                    ->values();

                if ($saleIds->isNotEmpty()) {
                    $rows = $this->applyStoreScope(DB::table($resource), $resource, $localStoreId)
                        ->whereIn('sale_id', $saleIds->all())
                        ->tap(fn (Builder $query) => $this->applyDefaultOrder($query, $resource))
                        ->get();
                }
            }

            $payloadRows = [];
            $rowsForResponse = [];

            foreach ($rows as $row) {
                $payload = (array) $row;

                if (
                    Schema::hasColumn($resource, 'local_id')
                    && trim((string) ($payload['local_id'] ?? '')) === ''
                ) {
                    $storeId = $this->resolveStoreIdForPayload($resource, $payload, $localStoreId);
                    $localId = app(LocalIdentifierService::class)->makeForTable($resource, $storeId > 0 ? $storeId : null);
                    $this->updateLocalRowSyncStatus($resource, $row, ['local_id' => $localId]);
                    $payload['local_id'] = $localId;
                }

                ['payload' => $payload, 'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys] = $this->mapLocalForeignKeysToServerIds($resource, $payload);
                ['payload' => $payload, 'has_unresolved_foreign_keys' => $hasUnresolvedMorphRelations] = $this->mapLocalMorphRelationsToServerIds($resource, $payload);

                if ($hasUnresolvedMorphRelations) {
                    $hasUnresolvedForeignKeys = true;
                }

                if ($hasUnresolvedForeignKeys) {
                    $this->updateLocalRowSyncStatus(
                        $resource,
                        $row,
                        [
                            'sync_state' => 'failed',
                            'sync_error' => 'Dependent records are not synced to cloud yet.',
                        ]
                    );

                    continue;
                }

                unset($payload['id'], $payload['sync_state'], $payload['synced_at'], $payload['sync_error']);
                $payloadRows[] = $payload;
                $rowsForResponse[] = $row;
            }

            if ($payloadRows === []) {
                continue;
            }

            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->post("/api/pos/v1/stores/{$serverStoreId}/sync/{$resource}/upsert", [
                    'rows' => $payloadRows,
                ]);

            if (! $response->successful()) {
                $error = (string) ($response->json('message') ?? $response->body() ?? 'Unable to sync resource.');
                foreach ($rowsForResponse as $row) {
                    $this->updateLocalRowSyncStatus(
                        $resource,
                        $row,
                        [
                            'sync_state' => 'failed',
                            'sync_error' => $error,
                        ]
                    );
                }

                continue;
            }

            $results = collect($response->json('results', []));

            foreach (collect($rowsForResponse)->values() as $index => $row) {
                $result = $results->firstWhere('index', $index);
                $status = (string) ($result['status'] ?? 'synced');
                $error = (string) ($result['error'] ?? '');

                if ($status === 'synced') {
                    $updates = [
                        'sync_state' => 'synced',
                        'synced_at' => now(),
                        'sync_error' => null,
                    ];

                    if (Schema::hasColumn($resource, 'server_id') && is_numeric($result['server_id'] ?? null)) {
                        $updates['server_id'] = (int) $result['server_id'];
                    }

                    if (Schema::hasColumn($resource, 'local_id') && filled($result['local_id'] ?? null)) {
                        $updates['local_id'] = (string) $result['local_id'];
                    }

                    if (Schema::hasColumn($resource, 'reference') && filled($result['reference'] ?? null)) {
                        $updates['reference'] = (string) $result['reference'];
                    }

                    $this->updateLocalRowSyncStatus($resource, $row, $updates);
                    $pushedCount++;
                } else {
                    $this->updateLocalRowSyncStatus(
                        $resource,
                        $row,
                        [
                            'sync_state' => 'failed',
                            'sync_error' => $error !== '' ? $error : 'Row sync failed.',
                        ]
                    );
                }
            }
        }

        return $pushedCount;
    }

    private function pullAllResources(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = []): int
    {
        $pulled = 0;
        $resourceList = ! empty($resources) ? $resources : self::PULL_ORDER;
        $deferredRowsByResource = [];
        $runtimeStateService = app(RuntimeStateService::class);

        foreach ($resourceList as $resource) {
            $page = 1;
            $hasMore = true;

            // Surface what's happening during reconcile/backfill so the bootstrap
            // UI doesn't appear frozen at 99% while we paginate large resources.
            $runtimeStateService->updateStoreSyncState($localStoreId, [
                'bootstrap_progress_label' => "Reconciling {$resource}",
            ]);

            while ($hasMore) {
                $response = Http::baseUrl($baseUrl)
                    ->withToken($token)
                    ->acceptJson()
                    ->connectTimeout(15)
                    ->timeout(0)
                    ->get("/api/pos/v1/stores/{$serverStoreId}/sync/{$resource}", [
                        'page' => $page,
                        'per_page' => self::PULL_PER_PAGE,
                    ]);

                if (! $response->successful()) {
                    break;
                }

                $rows = (array) ($response->json('data') ?? []);
                $deferredRows = $this->upsertPulledRows($resource, $rows, $localStoreId);
                if ($deferredRows !== []) {
                    $deferredRowsByResource[$resource] = [
                        ...($deferredRowsByResource[$resource] ?? []),
                        ...$deferredRows,
                    ];
                }
                $pulled += count($rows);

                $currentPage = (int) ($response->json('meta.current_page') ?? $page);
                $lastPage = (int) ($response->json('meta.last_page') ?? $currentPage);
                $hasMore = $currentPage < $lastPage;
                $page++;
            }
        }

        if ($deferredRowsByResource !== []) {
            $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
        }

        return $pulled;
    }

    private function pullResourceChunk(
        string $baseUrl,
        string $token,
        int $serverStoreId,
        int $localStoreId,
        array $resources,
        string $resource,
        int $startPage,
        int $maxPages
    ): array {
        $resourceList = $resources === [] ? self::PULL_ORDER : array_values($resources);
        $resourceIndex = array_search($resource, $resourceList, true);
        if ($resourceIndex === false) {
            return [
                'ok' => false,
                'message' => 'Invalid resource chunk requested.',
            ];
        }

        $pulled = 0;
        $page = max(1, $startPage);
        $processedPages = 0;
        $deferredRowsByResource = [];

        while ($processedPages < max(1, $maxPages)) {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->get("/api/pos/v1/stores/{$serverStoreId}/sync/{$resource}", [
                    'page' => $page,
                    'per_page' => self::PULL_PER_PAGE,
                ]);

            if (! $response->successful()) {
                $status = (int) $response->status();
                $message = (string) ($response->json('message') ?? $response->body() ?? 'Unable to pull cloud resource.');

                if ($status === 422 && str_contains(strtolower($message), 'unsupported resource')) {
                    $nextResource = $resourceList[$resourceIndex + 1] ?? null;
                    if (! is_string($nextResource) || $nextResource === '') {
                        return [
                            'ok' => true,
                            'pulled' => $pulled,
                            'next_resource' => null,
                            'next_page' => null,
                        ];
                    }

                    return [
                        'ok' => true,
                        'pulled' => $pulled,
                        'next_resource' => $nextResource,
                        'next_page' => 1,
                    ];
                }

                return [
                    'ok' => false,
                    'message' => $message,
                ];
            }

            $rows = (array) ($response->json('data') ?? []);
            $deferredRows = $this->upsertPulledRows($resource, $rows, $localStoreId);
            if ($deferredRows !== []) {
                $deferredRowsByResource[$resource] = [
                    ...($deferredRowsByResource[$resource] ?? []),
                    ...$deferredRows,
                ];
            }
            $pulled += count($rows);
            $processedPages++;

            $currentPage = (int) ($response->json('meta.current_page') ?? $page);
            $lastPage = (int) ($response->json('meta.last_page') ?? $currentPage);

            if ($currentPage < $lastPage) {
                $page++;

                continue;
            }

            if ($deferredRowsByResource !== []) {
                $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
            }

            $nextResource = $resourceList[$resourceIndex + 1] ?? null;
            if (! is_string($nextResource) || $nextResource === '') {
                return [
                    'ok' => true,
                    'pulled' => $pulled,
                    'next_resource' => null,
                    'next_page' => null,
                ];
            }

            return [
                'ok' => true,
                'pulled' => $pulled,
                'next_resource' => $nextResource,
                'next_page' => 1,
            ];
        }

        if ($deferredRowsByResource !== []) {
            $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
        }

        return [
            'ok' => true,
            'pulled' => $pulled,
            'next_resource' => $resource,
            'next_page' => $page,
        ];
    }

    private function hasValidCloudToken(string $baseUrl, string $token): bool
    {
        // Short timeouts: this is a connectivity probe, not a real workload.
        // If the network is down or the server is wedged, fail fast so the
        // queue worker can move on instead of hanging Guzzle's default 30s.
        try {
            $authCheck = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get('/api/pos/user');
        } catch (\Throwable $e) {
            return false;
        }

        return $authCheck->successful();
    }

    private function installBootstrapSnapshot(string $snapshotPath, int $localStoreId, array $manifest): int
    {
        if (! is_file($snapshotPath)) {
            throw new RuntimeException('Bootstrap snapshot file is missing.');
        }

        $resourceTotals = collect((array) ($manifest['resources'] ?? []))
            ->mapWithKeys(fn (array $resource): array => [(string) $resource['resource'] => (int) ($resource['total'] ?? 0)])
            ->all();
        $totalRows = max(1, (int) ($manifest['total_rows'] ?? 0));
        $processedRows = 0;
        $installed = 0;
        $runtimeStateService = app(RuntimeStateService::class);
        $deferredRowsByResource = [];

        $handle = fopen($snapshotPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open bootstrap snapshot.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $resource = (string) ($payload['resource'] ?? '');
                $rows = (array) ($payload['rows'] ?? []);

                if ($resource === '') {
                    continue;
                }

                $deferredRows = $this->upsertPulledRows($resource, $rows, $localStoreId);
                $installed += count($rows);
                $processedRows += count($rows);
                if ($deferredRows !== []) {
                    $deferredRowsByResource[$resource] = [
                        ...($deferredRowsByResource[$resource] ?? []),
                        ...$deferredRows,
                    ];
                }

                $resourceTotal = max(1, (int) ($resourceTotals[$resource] ?? count($rows)));
                $resourceProcessed = min($resourceTotal, max(0, (int) ($payload['page'] ?? 1) * count($rows)));
                $progress = 60 + (int) floor(($processedRows / $totalRows) * 40);

                $runtimeStateService->updateBootstrapProgress(
                    $localStoreId,
                    min($progress, 99),
                    "Installing {$resource} ({$processedRows}/{$totalRows})"
                );
            }
        } finally {
            fclose($handle);
        }

        if ($deferredRowsByResource !== []) {
            $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
        }

        return $installed;
    }

    private function applyDeltaResources(array $resources, int $localStoreId): int
    {
        $pulled = 0;
        $deferredRowsByResource = [];
        $deferredTombstonesByResource = [];

        foreach ($resources as $resourcePayload) {
            if (! is_array($resourcePayload)) {
                continue;
            }

            $resource = (string) ($resourcePayload['resource'] ?? '');
            $rows = (array) ($resourcePayload['rows'] ?? []);
            $tombstones = (array) ($resourcePayload['tombstones'] ?? []);

            if ($resource === '' || ! Schema::hasTable($resource)) {
                continue;
            }

            $deferredRows = $this->upsertPulledRows($resource, $rows, $localStoreId);
            $pulled += count($rows);

            $deferredTombstones = $this->applyTombstones($resource, $tombstones, $localStoreId);

            if ($deferredRows !== []) {
                $deferredRowsByResource[$resource] = [
                    ...($deferredRowsByResource[$resource] ?? []),
                    ...$deferredRows,
                ];
            }

            if ($deferredTombstones !== []) {
                $deferredTombstonesByResource[$resource] = [
                    ...($deferredTombstonesByResource[$resource] ?? []),
                    ...$deferredTombstones,
                ];
            }
        }

        if ($deferredRowsByResource !== []) {
            $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
        }

        if ($deferredTombstonesByResource !== []) {
            // Park tombstones so the next sync (after local push completes)
            // can re-apply them. Without this, a tombstone for a locally-
            // edited row was silently dropped and the row stayed alive on
            // every device except the one that issued the delete.
            $this->parkDeferredInboundRows($deferredTombstonesByResource, $localStoreId, 'tombstone');
        }

        return $pulled;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $deferredRowsByResource
     */
    private function retryDeferredPullRows(array $deferredRowsByResource, int $localStoreId): void
    {
        $remaining = $deferredRowsByResource;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $resolvedAny = false;

            foreach (self::PULL_ORDER as $resource) {
                $rows = $remaining[$resource] ?? [];
                if ($rows === []) {
                    continue;
                }

                $stillDeferred = $this->upsertPulledRows($resource, $rows, $localStoreId);
                if (count($stillDeferred) < count($rows)) {
                    $resolvedAny = true;
                }

                if ($stillDeferred === []) {
                    unset($remaining[$resource]);
                } else {
                    $remaining[$resource] = $stillDeferred;
                }
            }

            if ($remaining === [] || ! $resolvedAny) {
                break;
            }
        }

        // Anything still unresolved after in-memory retries gets parked in a
        // table so the NEXT sync cycle can try again. Previously these rows
        // were silently dropped after 5 attempts — the visible symptom was
        // "sales sync but their line items don't" when a new variation/stock
        // referenced by the sale wasn't already in the local database.
        if ($remaining !== []) {
            $this->parkDeferredInboundRows($remaining, $localStoreId);
        }
    }

    /**
     * Persist deferred pulled rows for retry on subsequent sync cycles.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $deferredRowsByResource
     * @param  string  $kind  'upsert' (default) or 'tombstone' — drives how
     *                        processPendingInboundRows replays the row.
     *                        Encoded inside the JSON payload as `__kind` so we
     *                        don't have to migrate the parking table.
     */
    private function parkDeferredInboundRows(array $deferredRowsByResource, int $localStoreId, string $kind = 'upsert'): void
    {
        if (! Schema::hasTable('pending_inbound_sync_rows')) {
            return;
        }

        $now = now();
        $insertRows = [];

        $errorLabel = match ($kind) {
            'tombstone' => 'Local row has pending edits; tombstone will retry after push.',
            default => 'Foreign-key dependencies missing locally; will retry on next sync.',
        };

        foreach ($deferredRowsByResource as $resource => $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Sentinel so processPendingInboundRows knows whether to call
                // upsertPulledRows or applyTombstones on replay.
                $row['__kind'] = $kind;

                $insertRows[] = [
                    'resource' => $resource,
                    'store_id' => $localStoreId,
                    'payload' => json_encode($row),
                    'attempts' => 0,
                    'last_error' => $errorLabel,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($insertRows === []) {
            return;
        }

        // Chunk to keep prepared-statement bind counts sane.
        foreach (array_chunk($insertRows, 100) as $batch) {
            DB::table('pending_inbound_sync_rows')->insert($batch);
        }
    }

    /**
     * Re-apply any rows that previous sync runs couldn't resolve. Called at
     * the start of every delta cycle so dependencies that arrived in later
     * pulls (or were created locally) finally let the rows in.
     */
    private function processPendingInboundRows(int $localStoreId): void
    {
        if (! Schema::hasTable('pending_inbound_sync_rows')) {
            return;
        }

        $stale = DB::table('pending_inbound_sync_rows')
            ->where('store_id', $localStoreId)
            ->where('attempts', '>=', 50)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            // Permanently abandon rows that have failed dozens of times — at
            // that point the dependency is genuinely missing on this device
            // and a full bootstrap is the right recovery, not infinite retry.
            DB::table('pending_inbound_sync_rows')->whereIn('id', $stale)->delete();
        }

        $pending = DB::table('pending_inbound_sync_rows')
            ->where('store_id', $localStoreId)
            ->orderByRaw('CASE resource '.$this->resourceOrderCase().' ELSE 999 END')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        // Group by (resource, kind) so we route tombstones to applyTombstones
        // and regular rows to upsertPulledRows on replay.
        $rowsByResourceKind = [];
        $idsByResourceKind = [];

        foreach ($pending as $entry) {
            $payload = json_decode((string) $entry->payload, true);
            if (! is_array($payload)) {
                DB::table('pending_inbound_sync_rows')->where('id', $entry->id)->delete();

                continue;
            }

            $kind = (string) ($payload['__kind'] ?? 'upsert');
            unset($payload['__kind']);

            $resource = (string) $entry->resource;
            $key = "{$resource}::{$kind}";

            $rowsByResourceKind[$key] ??= ['resource' => $resource, 'kind' => $kind, 'rows' => []];
            $rowsByResourceKind[$key]['rows'][] = $payload;
            $idsByResourceKind[$key] ??= [];
            $idsByResourceKind[$key][] = (int) $entry->id;
        }

        // Process upserts first (PULL_ORDER), then tombstones — so any
        // dependencies the tombstoned row's siblings need are present.
        foreach (['upsert', 'tombstone'] as $kind) {
            foreach (self::PULL_ORDER as $resource) {
                $key = "{$resource}::{$kind}";
                $group = $rowsByResourceKind[$key] ?? null;
                if ($group === null || $group['rows'] === []) {
                    continue;
                }

                $rows = $group['rows'];
                $ids = $idsByResourceKind[$key];

                if ($kind === 'tombstone') {
                    $stillDeferred = $this->applyTombstones($resource, $rows, $localStoreId);
                } else {
                    $stillDeferred = $this->upsertPulledRows($resource, $rows, $localStoreId);
                }

                $resolvedCount = count($rows) - count($stillDeferred);

                if ($resolvedCount > 0) {
                    // Wipe everything we had parked for this (resource, kind)
                    // and re-park only what is still unresolved. Simpler and
                    // safer than correlating individual rows to row-ids.
                    DB::table('pending_inbound_sync_rows')
                        ->whereIn('id', $ids)
                        ->delete();

                    if ($stillDeferred !== []) {
                        $this->parkDeferredInboundRows([$resource => $stillDeferred], $localStoreId, $kind);
                        DB::table('pending_inbound_sync_rows')
                            ->where('store_id', $localStoreId)
                            ->where('resource', $resource)
                            ->where('created_at', '>=', now()->subSecond())
                            ->update(['attempts' => DB::raw('attempts + 1')]);
                    }
                } else {
                    DB::table('pending_inbound_sync_rows')
                        ->whereIn('id', $ids)
                        ->update(['attempts' => DB::raw('attempts + 1')]);
                }
            }
        }
    }

    private function resourceOrderCase(): string
    {
        $cases = [];
        foreach (self::PULL_ORDER as $index => $resource) {
            $cases[] = 'WHEN '.DB::getPdo()->quote($resource)." THEN {$index}";
        }

        return implode(' ', $cases);
    }

    private function reconcileBootstrapResources(
        string $baseUrl,
        string $token,
        int $serverStoreId,
        int $localStoreId,
        array $manifest,
    ): int {
        $resourcesToReconcile = collect((array) ($manifest['resources'] ?? []))
            ->filter(function (array $resourceMeta) use ($localStoreId): bool {
                $resource = (string) ($resourceMeta['resource'] ?? '');
                $expectedTotal = (int) ($resourceMeta['total'] ?? 0);

                if ($resource === '' || $expectedTotal <= 0) {
                    return false;
                }

                return $this->countLocalRowsForResource($resource, $localStoreId) < $expectedTotal;
            })
            ->pluck('resource')
            ->values()
            ->all();

        if ($resourcesToReconcile === []) {
            return 0;
        }

        return $this->pullAllResources($baseUrl, $token, $serverStoreId, $localStoreId, $resourcesToReconcile);
    }

    private function countLocalRowsForResource(string $resource, int $localStoreId): int
    {
        if (! Schema::hasTable($resource)) {
            return 0;
        }

        return (int) $this->applyStoreScope(DB::table($resource), $resource, $localStoreId)->count();
    }

    /**
     * Apply incoming tombstones to local rows. Returns the list of tombstones
     * we couldn't apply yet (because the local row has pending unpushed edits
     * — we don't want to silently destroy the user's local change before it
     * has a chance to reach the server). Returned tombstones are parked by
     * the caller for retry after the next push completes.
     *
     * @return array<int, array<string, mixed>> deferred tombstones to park
     */
    private function applyTombstones(string $resource, array $tombstones, int $localStoreId): array
    {
        if (! Schema::hasTable($resource)) {
            return [];
        }

        $deferred = [];

        foreach ($tombstones as $tombstone) {
            if (! is_array($tombstone)) {
                continue;
            }

            $localRow = $this->findExistingLocalRow($resource, $tombstone, $localStoreId);
            if ($localRow === null) {
                // Already gone locally — nothing to delete.
                continue;
            }

            // If the local row has pending unpushed edits, park the tombstone
            // and let the push run first. On the next sync the row will be
            // synced (or rejected by the server) and we'll re-evaluate. This
            // is the right precedence: NEVER discard a user's local change
            // implicitly. Either the user's edit lands on the server (then
            // the server may re-tombstone if it really wanted the row gone)
            // or it doesn't (then the tombstone still applies next round).
            $isLocalPending = Schema::hasColumn($resource, 'sync_state')
                && (string) ($localRow->sync_state ?? '') === 'pending';

            if ($isLocalPending) {
                $deferred[] = $tombstone;

                continue;
            }

            // For a synced local row, the server's delete is authoritative —
            // apply it even when local updated_at is newer (e.g. an offline
            // edit that already reached us via another channel). The user
            // explicitly deleted on the server; respect that.
            $updates = [];
            if (Schema::hasColumn($resource, 'deleted_at')) {
                $updates['deleted_at'] = $tombstone['deleted_at'] ?? now();
            }
            if (Schema::hasColumn($resource, 'sync_state')) {
                $updates['sync_state'] = 'synced';
            }
            if (Schema::hasColumn($resource, 'synced_at')) {
                $updates['synced_at'] = now();
            }
            if (Schema::hasColumn($resource, 'sync_error')) {
                $updates['sync_error'] = null;
            }

            if ($updates !== []) {
                DB::table($resource)->where('id', (int) $localRow->id)->update($updates);
            }
        }

        return $deferred;
    }

    private function pushPendingRowsV2(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = [], ?float $deadline = null): int
    {
        $resourceList = $resources !== [] ? $resources : self::PULL_ORDER;
        $pushedCount = 0;

        // Cross-process lock per (store, push) so two queue workers can't
        // race on the same pending rows and double-post them to the server.
        // Without this, the worker started by the observer and the worker
        // started by the auto-sync poller can pick up the same row in the
        // microsecond between SELECT and UPDATE-sync_state, both POST to
        // /delta/upsert, and we get duplicate writes on the server.
        $lock = Cache::lock("cloud-push-{$localStoreId}", 60);
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->doPushPendingRowsV2($baseUrl, $token, $serverStoreId, $localStoreId, $resourceList, $deadline);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  float|null  $deadline  optional `microtime(true)`-style cutoff.
     *                                When set, we bail between resource batches once exceeded so
     *                                the caller (typically the store-switch middleware) doesn't
     *                                hang the UI indefinitely against a slow or offline server.
     */
    private function doPushPendingRowsV2(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resourceList, ?float $deadline = null): int
    {
        $pushedCount = 0;

        foreach ($resourceList as $resource) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            if (! Schema::hasTable($resource) || ! Schema::hasColumn($resource, 'sync_state')) {
                continue;
            }

            $rows = $this->applyStoreScope(DB::table($resource), $resource, $localStoreId)
                ->whereIn('sync_state', ['pending', 'failed'])
                ->tap(fn (Builder $query) => $this->applyDefaultOrder($query, $resource))
                ->limit(200)
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $payloadRows = [];
            $rowsForResponse = [];

            foreach ($rows as $row) {
                $payload = (array) $row;

                if (Schema::hasColumn($resource, 'local_id') && trim((string) ($payload['local_id'] ?? '')) === '') {
                    $storeId = $this->resolveStoreIdForPayload($resource, $payload, $localStoreId);
                    $localId = app(LocalIdentifierService::class)->makeForTable($resource, $storeId > 0 ? $storeId : null);
                    $this->updateLocalRowSyncStatus($resource, $row, ['local_id' => $localId]);
                    $payload['local_id'] = $localId;
                }

                ['payload' => $payload, 'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys] = $this->mapLocalForeignKeysToServerIds($resource, $payload);
                ['payload' => $payload, 'has_unresolved_foreign_keys' => $hasUnresolvedMorphRelations] = $this->mapLocalMorphRelationsToServerIds($resource, $payload);

                if ($hasUnresolvedMorphRelations) {
                    $hasUnresolvedForeignKeys = true;
                }

                if ($hasUnresolvedForeignKeys) {
                    $this->updateLocalRowSyncStatus($resource, $row, [
                        'sync_state' => 'failed',
                        'sync_error' => 'Dependent records are not synced to cloud yet.',
                    ]);

                    continue;
                }

                unset($payload['id'], $payload['sync_state'], $payload['synced_at'], $payload['sync_error']);

                // Final defensive check: the payload must carry at least ONE
                // identifier the server can match a row by. Otherwise the
                // server's updateOrInsert has nothing to anchor on and will
                // insert a new row that drifts away from our local one.
                // This guards against rows that lost their local_id/server_id
                // through some bug (e.g. a manual SQL fix).
                if (! $this->payloadHasUsableIdentifier($resource, $payload)) {
                    $this->updateLocalRowSyncStatus($resource, $row, [
                        'sync_state' => 'failed',
                        'sync_error' => 'Row has no server_id, local_id, or natural key — refusing to push as it would create a duplicate on the server.',
                    ]);

                    continue;
                }

                $payloadRows[] = $payload;
                $rowsForResponse[] = $row;
            }

            if ($payloadRows === []) {
                continue;
            }

            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->post("/api/pos/v2/stores/{$serverStoreId}/delta/upsert", [
                    'resources' => [[
                        'resource' => $resource,
                        'rows' => $payloadRows,
                        'replace_by_sale' => in_array($resource, ['sale_variation', 'sale_preparable_items'], true),
                    ]],
                ]);

            if (! $response->successful()) {
                $error = (string) ($response->json('message') ?? $response->body() ?: 'Unable to push local changes.');

                foreach ($rowsForResponse as $row) {
                    $this->updateLocalRowSyncStatus($resource, $row, [
                        'sync_state' => 'failed',
                        'sync_error' => $error,
                    ]);
                }

                continue;
            }

            $results = collect((array) data_get($response->json(), 'resources.0.results', []));

            foreach (array_values($rowsForResponse) as $index => $row) {
                $result = $results->firstWhere('index', $index);
                $status = (string) ($result['status'] ?? 'synced');
                $error = (string) ($result['error'] ?? '');

                if ($status === 'synced') {
                    $updates = [
                        'sync_state' => 'synced',
                        'synced_at' => now(),
                        'sync_error' => null,
                    ];

                    if (Schema::hasColumn($resource, 'server_id') && is_numeric($result['server_id'] ?? null)) {
                        $updates['server_id'] = (int) $result['server_id'];
                    }

                    if (Schema::hasColumn($resource, 'local_id') && filled($result['local_id'] ?? null)) {
                        $updates['local_id'] = (string) $result['local_id'];
                    }

                    if (Schema::hasColumn($resource, 'reference') && filled($result['reference'] ?? null)) {
                        $updates['reference'] = (string) $result['reference'];
                    }

                    // CAS: only flip to synced when the row is still at the
                    // updated_at we read it at. If a fresh local edit landed
                    // mid-push, leave sync_state='pending' so the next push
                    // round ships the new version.
                    if ($this->flipRowToSyncedIfUnchanged($resource, $row, $updates)) {
                        $pushedCount++;
                    }
                } else {
                    $this->updateLocalRowSyncStatus($resource, $row, [
                        'sync_state' => 'failed',
                        'sync_error' => $error !== '' ? $error : 'Row sync failed.',
                    ]);
                }
            }
        }

        return $pushedCount;
    }

    /**
     * Drain pending delete tombstones from sync_outbox to the cloud server.
     *
     * Each tombstone identifies a row by server_id (preferred) or local_id
     * (fallback) scoped to a store. The server soft-deletes the matching row
     * so the existing outbound delta() flow can propagate the deletion to
     * every OTHER device the next time they pull.
     *
     * Resources is the same allow-list used by push so that a per-module
     * push (e.g. "customers") only flushes tombstones for that module.
     */
    private function pushTombstonesV2(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = [], ?float $deadline = null): int
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            return 0;
        }
        if (! Schema::hasTable('sync_outbox')) {
            return 0;
        }

        $resourceList = $resources !== [] ? $resources : self::PULL_ORDER;

        $rows = DB::table('sync_outbox')
            ->where('operation', 'delete')
            ->whereIn('status', ['pending', 'failed'])
            ->whereIn('entity_type', $resourceList)
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        // Filter to tombstones that belong to THIS store (store_id is stashed
        // in the payload JSON, since sync_outbox itself has no store column).
        $tombstonePayload = [];
        $rowsForResponse = [];

        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? (array) json_decode($row->payload, true) : [];
            $rowStoreId = (int) ($payload['store_id'] ?? 0);

            if ($rowStoreId !== 0 && $rowStoreId !== $localStoreId) {
                continue;
            }

            $serverId = $row->server_id !== null ? (int) $row->server_id : null;
            $localIdString = isset($payload['local_id']) && $payload['local_id'] !== ''
                ? (string) $payload['local_id']
                : null;

            if ($serverId === null && $localIdString === null) {
                // Nothing to anchor on. Mark failed so it stops being retried.
                DB::table('sync_outbox')->where('id', $row->id)->update([
                    'status' => 'failed',
                    'error' => 'Tombstone has neither server_id nor local_id.',
                    'attempts' => (int) $row->attempts + 1,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $tombstonePayload[] = array_filter([
                'resource' => (string) $row->entity_type,
                'server_id' => $serverId,
                'local_id' => $localIdString,
            ], fn ($value) => $value !== null);
            $rowsForResponse[] = $row;
        }

        if ($tombstonePayload === []) {
            return 0;
        }

        $response = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->post("/api/pos/v2/stores/{$serverStoreId}/delta/tombstones", [
                'tombstones' => $tombstonePayload,
            ]);

        if (! $response->successful()) {
            $error = (string) ($response->json('message') ?? $response->body() ?: 'Unable to push tombstones.');

            foreach ($rowsForResponse as $row) {
                DB::table('sync_outbox')->where('id', $row->id)->update([
                    'status' => 'failed',
                    'error' => $error,
                    'attempts' => (int) $row->attempts + 1,
                    'updated_at' => now(),
                ]);
            }

            return 0;
        }

        $results = collect((array) data_get($response->json(), 'results', []));
        $tombstoned = 0;

        foreach (array_values($rowsForResponse) as $index => $row) {
            $result = $results->firstWhere('index', $index);
            $status = (string) ($result['status'] ?? 'tombstoned');

            if ($status === 'tombstoned') {
                DB::table('sync_outbox')->where('id', $row->id)->delete();
                $tombstoned++;
            } else {
                DB::table('sync_outbox')->where('id', $row->id)->update([
                    'status' => 'failed',
                    'error' => (string) ($result['error'] ?? 'Tombstone rejected by server.'),
                    'attempts' => (int) $row->attempts + 1,
                    'updated_at' => now(),
                ]);
            }
        }

        return $tombstoned;
    }

    private function findExistingLocalRow(string $table, array $payload, int $localStoreId): ?object
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        if (Schema::hasColumn($table, 'server_id') && is_numeric($payload['server_id'] ?? null)) {
            $query = DB::table($table)->where('server_id', (int) $payload['server_id']);

            if (Schema::hasColumn($table, 'store_id') && $table !== 'stores') {
                $query->where('store_id', $localStoreId);
            }

            return $query->first();
        }

        if (Schema::hasColumn($table, 'local_id') && filled($payload['local_id'] ?? null)) {
            $query = DB::table($table)->where('local_id', (string) $payload['local_id']);

            if (Schema::hasColumn($table, 'store_id') && $table !== 'stores') {
                $query->where('store_id', $localStoreId);
            }

            return $query->first();
        }

        $compositeLookup = $this->resolveCompositeLookupForPulledRow($table, $payload);
        if ($compositeLookup !== []) {
            return DB::table($table)->where($compositeLookup)->first();
        }

        return null;
    }

    /**
     * Local-priority sync policy. A pulled server row is only allowed to
     * overwrite a local row when ALL of these are false:
     *
     *  1. Local row has unsynced edits (sync_state in pending/failed) — the
     *     user changed something offline and the push hasn't run yet.
     *  2. Local row has a pending outbox entry — same idea, tracked
     *     separately for some resources.
     *  3. Local row was pushed within the echo window (~5 min). The server
     *     re-emits everything we just sent, often with slightly different
     *     normalization; without this guard, a fresh push gets immediately
     *     overwritten by its own echo and the totals/references end up
     *     looking wrong to the user.
     *  4. Local row's updated_at is strictly newer than the server's — the
     *     local copy already represents a later edit, so server data would
     *     be a regression.
     *
     * The user explicitly asked for "local changes are top priority"; if
     * another device legitimately modifies the same row, that change is
     * deferred until the local row is no longer winning by these rules.
     */
    private function shouldSkipPulledRow(string $table, object $localRow, int $localStoreId, array $serverPayload = []): bool
    {
        if (property_exists($localRow, 'sync_state') && in_array((string) ($localRow->sync_state ?? ''), ['pending', 'failed'], true)) {
            return true;
        }

        $localId = (int) ($localRow->id ?? 0);
        if ($localId > 0) {
            $hasPendingOutbox = SyncOutbox::query()
                ->where('entity_type', $table)
                ->where('local_id', $localId)
                ->whereIn('status', ['pending', 'failed'])
                ->exists();

            if ($hasPendingOutbox) {
                return true;
            }
        }

        // Note: an earlier version of this method also rejected rows whose
        // synced_at was within a "5-minute echo window". That over-blocked
        // legitimate server updates whose only sin was that the local row
        // had a fresh synced_at from a prior sync. The combination of (1)
        // and the updated_at comparison below already prevents the original
        // problem (local edits getting clobbered by server echoes) — if the
        // user has edited the row, sync_state flips to pending OR
        // updated_at advances, either of which blocks the overwrite.

        // Strict timestamp protection: if our copy is newer, keep it.
        $localUpdatedAt = property_exists($localRow, 'updated_at') ? $localRow->updated_at : null;
        $localTs = $this->toTimestamp($localUpdatedAt);
        $serverTs = $this->toTimestamp($serverPayload['updated_at'] ?? null);

        if ($localTs !== null && $serverTs !== null && $localTs > $serverTs) {
            return true;
        }

        return false;
    }

    /**
     * Coerce mixed date/datetime values (string, DateTime, Carbon) to a
     * unix timestamp, or null if unparseable. Centralised so the skip
     * policy reads the same way for every field.
     */
    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);

            return $parsed === false ? null : $parsed;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function resolveResourcesForModule(?string $module): array
    {
        if ($module === null) {
            return [];
        }

        return self::MODULE_DEFINITIONS[$module]['resources'] ?? [];
    }

    private function hasPendingRowsForResources(int $localStoreId, array $resources): bool
    {
        foreach ($resources as $resource) {
            if (! Schema::hasTable($resource) || ! Schema::hasColumn($resource, 'sync_state')) {
                continue;
            }

            $hasPendingRows = $this->applyStoreScope(DB::table($resource), $resource, $localStoreId)
                ->whereIn('sync_state', ['pending', 'failed'])
                ->exists();

            if ($hasPendingRows) {
                return true;
            }
        }

        return false;
    }

    private function applyStoreScope(Builder $query, string $table, int $storeId): Builder
    {
        if ($storeId <= 0) {
            return $query;
        }

        if ($table === 'stores') {
            return $query->where('id', $storeId);
        }

        if (Schema::hasColumn($table, 'store_id')) {
            return $query->where('store_id', $storeId);
        }

        if ($table === 'sale_variation' || $table === 'sale_preparable_items') {
            return $query->whereIn('sale_id', DB::table('sales')->where('store_id', $storeId)->select('id'));
        }

        if ($table === 'purchase_order_products') {
            return $query->whereIn('purchase_order_id', DB::table('purchase_orders')->where('store_id', $storeId)->select('id'));
        }

        if ($table === 'product_attributes') {
            return $query->whereIn('product_id', DB::table('products')->where('store_id', $storeId)->select('id'));
        }

        return $query;
    }

    private function canScopeTableToStore(string $table): bool
    {
        return $table === 'stores'
            || Schema::hasColumn($table, 'store_id')
            || in_array($table, ['sale_variation', 'sale_preparable_items', 'purchase_order_products', 'product_attributes'], true);
    }

    private function moduleKeyByResource(): array
    {
        $map = [];

        foreach (self::MODULE_DEFINITIONS as $moduleKey => $definition) {
            foreach (($definition['resources'] ?? []) as $resource) {
                $map[$resource] = $moduleKey;
            }
        }

        return $map;
    }

    private function normalizeListedTableName(string $listedTable): string
    {
        $table = trim($listedTable);
        if ($table === '') {
            return '';
        }

        if (str_contains($table, '.')) {
            $segments = explode('.', $table);
            $table = (string) end($segments);
        }

        return trim($table, '"`[]');
    }

    private function syncOutboxRowBelongsToStore(SyncOutbox $row, int $localStoreId): bool
    {
        $table = (string) $row->entity_type;
        $localId = (int) ($row->local_id ?? 0);

        if ($localStoreId <= 0 || $table === '' || ! Schema::hasTable($table) || $localId <= 0) {
            return false;
        }

        if ($table === 'stores') {
            return $localId === $localStoreId;
        }

        if (Schema::hasColumn($table, 'store_id')) {
            return DB::table($table)
                ->where('id', $localId)
                ->where('store_id', $localStoreId)
                ->exists();
        }

        if ($table === 'sale_variation' || $table === 'sale_preparable_items') {
            return DB::table($table)
                ->where('id', $localId)
                ->whereIn('sale_id', DB::table('sales')->where('store_id', $localStoreId)->select('id'))
                ->exists();
        }

        if ($table === 'purchase_order_products') {
            return DB::table($table)
                ->where('id', $localId)
                ->whereIn('purchase_order_id', DB::table('purchase_orders')->where('store_id', $localStoreId)->select('id'))
                ->exists();
        }

        if ($table === 'product_attributes') {
            return DB::table($table)
                ->where('id', $localId)
                ->whereIn('product_id', DB::table('products')->where('store_id', $localStoreId)->select('id'))
                ->exists();
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function upsertPulledRows(string $table, array $rows, int $localStoreId): array
    {
        if (! $this->cachedHasTable($table) || empty($rows)) {
            return [];
        }

        $columns = collect($this->cachedColumnListing($table));
        $deferredRows = [];

        // Pre-fetch every (related_table, server_id) → local_id mapping the
        // chunk will need. Without this, each row's FK + morph resolution
        // costs one SELECT per FK column — for a chunk of 200 sale_variation
        // rows that's ~1000 round trips just for sale_id/variation_id/
        // stock_id lookups. With it, the entire chunk needs ~3 SELECTs.
        $fkCache = $this->prefetchForeignKeyMap($table, $rows, $localStoreId);
        $morphCache = $this->prefetchMorphMap($table, $rows, $localStoreId);

        // Wrap the entire chunk in a single transaction so SQLite commits
        // once per chunk instead of once per row. On Windows POS installs
        // with default fsync behavior, this turns a ~50 rows/sec install
        // into a ~thousands of rows/sec install — the bootstrap difference
        // between minutes and seconds.
        return DB::transaction(function () use ($table, $rows, $localStoreId, $columns, $fkCache, $morphCache, &$deferredRows): array {
            $this->processUpsertRows($table, $rows, $localStoreId, $columns, $fkCache, $morphCache, $deferredRows);

            return $deferredRows;
        });
    }

    /**
     * Inner loop of upsertPulledRows, extracted so the surrounding
     * DB::transaction block stays a simple wrapper.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, string>  $columns
     * @param  array<string, array<int, int>>  $fkCache
     * @param  array<string, array<string, array<int, int>>>  $morphCache
     * @param  array<int, array<string, mixed>>  $deferredRows
     */
    private function processUpsertRows(string $table, array $rows, int $localStoreId, Collection $columns, array $fkCache, array $morphCache, array &$deferredRows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $serverId = $row['server_id'] ?? $row['id'] ?? null;
            $hasServerIdentity = is_numeric($serverId);

            $normalized = $row;
            if ($hasServerIdentity && $columns->contains('server_id')) {
                $normalized['server_id'] = (int) $serverId;
            }
            $normalized['sync_state'] = 'synced';
            $normalized['synced_at'] = now();
            $normalized['sync_error'] = null;

            if ($table === 'unit_dimensions' && $columns->contains('store_id')) {
                $normalized['store_id'] = $localStoreId;
            }

            if ($table === 'stores') {
                $existingStore = null;
                if ($hasServerIdentity && $columns->contains('server_id')) {
                    $existingStore = DB::table('stores')->where('server_id', (int) $serverId)->first();
                }

                if (! $existingStore && is_numeric($normalized['id'] ?? null)) {
                    $existingStore = DB::table('stores')->where('id', (int) $normalized['id'])->first();
                }

                if ($existingStore) {
                    $normalized['country_id'] = $existingStore->country_id ?? null;
                    $normalized['currency_id'] = $existingStore->currency_id ?? null;
                    $normalized['timezone_id'] = $existingStore->timezone_id ?? null;
                } else {
                    unset($normalized['country_id'], $normalized['currency_id'], $normalized['timezone_id']);
                }
            }

            ['payload' => $normalized, 'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys] = $this->mapForeignKeysToLocalIds($table, $normalized, $localStoreId, $fkCache);
            ['payload' => $normalized, 'has_unresolved_foreign_keys' => $hasUnresolvedMorphRelations] = $this->mapMorphRelationsToLocalIds($table, $normalized, $localStoreId, $morphCache);

            if ($hasUnresolvedMorphRelations) {
                $hasUnresolvedForeignKeys = true;
            }

            if ($hasUnresolvedForeignKeys) {
                $deferredRows[] = $row;

                continue;
            }

            unset($normalized['id']);
            $normalized = collect($normalized)
                ->filter(fn ($_, string $key): bool => $columns->contains($key))
                ->all();

            $existingLocalRow = $this->findExistingLocalRow($table, $normalized, $localStoreId);
            if ($existingLocalRow !== null && $this->shouldSkipPulledRow($table, $existingLocalRow, $localStoreId, $normalized)) {
                continue;
            }

            $compositeLookup = $this->resolveCompositeLookupForPulledRow($table, $normalized);

            if ($table === 'unit_dimensions' && isset($normalized['name'])) {
                $existingQuery = DB::table($table)
                    ->where('name', $normalized['name'])
                    ->where('store_id', $normalized['store_id'] ?? null);

                $existingId = $existingQuery->value('id');

                if (is_numeric($existingId)) {
                    DB::table($table)
                        ->where('id', (int) $existingId)
                        ->update($normalized);

                    continue;
                }
            }

            $existingByServerId = null;
            $existingByServerIdQuery = null;
            if ($columns->contains('server_id') && array_key_exists('server_id', $normalized) && is_numeric($normalized['server_id'])) {
                $existingByServerIdQuery = DB::table($table)
                    ->where('server_id', (int) $normalized['server_id']);

                if ($table === 'unit_dimensions' && isset($normalized['store_id'])) {
                    $existingByServerIdQuery->where('store_id', $normalized['store_id']);
                } elseif ($this->cachedHasColumn($table, 'store_id') && $table !== 'stores' && isset($normalized['store_id'])) {
                    $existingByServerIdQuery->where('store_id', $normalized['store_id']);
                }

                if ($this->cachedHasColumn($table, 'id')) {
                    $existingByServerId = $existingByServerIdQuery->value('id');
                } else {
                    $existingByServerId = $existingByServerIdQuery->exists() ? 1 : null;
                }
            }

            $existingByLocalId = null;
            $existingByLocalIdQuery = null;
            if ($this->cachedHasColumn($table, 'local_id') && trim((string) ($normalized['local_id'] ?? '')) !== '') {
                $existingByLocalIdQuery = DB::table($table)
                    ->where('local_id', (string) $normalized['local_id']);

                if ($table === 'unit_dimensions' && isset($normalized['store_id'])) {
                    $existingByLocalIdQuery->where('store_id', $normalized['store_id']);
                } elseif ($this->cachedHasColumn($table, 'store_id') && $table !== 'stores' && isset($normalized['store_id'])) {
                    $existingByLocalIdQuery->where('store_id', $normalized['store_id']);
                }

                if ($this->cachedHasColumn($table, 'id')) {
                    $existingByLocalId = $existingByLocalIdQuery->value('id');
                } else {
                    $existingByLocalId = $existingByLocalIdQuery->exists() ? 1 : null;
                }
            }

            $naturalLookup = $this->resolveNaturalUniqueLookup($table, $normalized);
            if (! empty($naturalLookup)) {
                $existingNaturalQuery = DB::table($table)->where($naturalLookup);

                if ($this->cachedHasColumn($table, 'id')) {
                    $existingNaturalRow = $existingNaturalQuery->first();
                    $existingId = is_object($existingNaturalRow) ? ($existingNaturalRow->id ?? null) : null;

                    if (is_numeric($existingId)) {
                        // Apply the same local-priority guard the top-level
                        // skip check uses, since natural keys (e.g. customer
                        // phone) can match a local row that findExistingLocalRow
                        // missed because we don't yet have a server_id mapping.
                        if ($this->shouldSkipPulledRow($table, $existingNaturalRow, $localStoreId, $normalized)) {
                            continue;
                        }

                        if (
                            is_numeric($existingByServerId)
                            && (int) $existingByServerId !== (int) $existingId
                            && $this->cachedHasColumn($table, 'server_id')
                        ) {
                            DB::table($table)
                                ->where('id', (int) $existingByServerId)
                                ->update(['server_id' => null]);
                        }

                        DB::table($table)
                            ->where('id', (int) $existingId)
                            ->update($normalized);

                        continue;
                    }
                } elseif ($existingNaturalQuery->exists()) {
                    $existingNaturalQuery->update($normalized);

                    continue;
                }
            }

            if (is_numeric($existingByLocalId)) {
                if ($this->cachedHasColumn($table, 'id')) {
                    DB::table($table)
                        ->where('id', (int) $existingByLocalId)
                        ->update($normalized);
                } elseif ($existingByLocalIdQuery !== null) {
                    $existingByLocalIdQuery->update($normalized);
                }

                continue;
            }

            if (is_numeric($existingByServerId)) {
                if ($this->cachedHasColumn($table, 'id')) {
                    DB::table($table)
                        ->where('id', (int) $existingByServerId)
                        ->update($normalized);
                } elseif ($existingByServerIdQuery !== null) {
                    $existingByServerIdQuery->update($normalized);
                }

                continue;
            }

            if ($compositeLookup !== []) {
                $existingByComposite = DB::table($table)->where($compositeLookup)->exists();
                if ($existingByComposite) {
                    DB::table($table)->where($compositeLookup)->update($normalized);

                    continue;
                }
            }

            if ($hasServerIdentity && $columns->contains('server_id')) {
                $lookup = ['server_id' => (int) $serverId];
                if ($table === 'unit_dimensions' && isset($normalized['store_id'])) {
                    $lookup['store_id'] = $normalized['store_id'];
                }

                DB::table($table)->updateOrInsert($lookup, $normalized);

                continue;
            }

            if ($compositeLookup !== []) {
                DB::table($table)->updateOrInsert($compositeLookup, $normalized);
            }
        }
    }

    /**
     * @param  array<string, array<int, int>>|null  $fkCache  optional pre-fetched
     *                                                        map of column => (server_id => local_id). When provided,
     *                                                        FK resolution is an O(1) array lookup instead of a per-row SELECT —
     *                                                        critical for the bootstrap installer's chunked workload.
     */
    private function mapForeignKeysToLocalIds(string $table, array $payload, int $localStoreId, ?array $fkCache = null): array
    {
        $hasUnresolvedForeignKeys = false;

        foreach (self::FOREIGN_KEY_TABLE_MAP as $column => $relatedTable) {
            if (! array_key_exists($column, $payload) || ! is_numeric($payload[$column])) {
                continue;
            }

            if (! $this->cachedHasTable($relatedTable)) {
                continue;
            }

            // Pull sync is done store-by-store; always scope related rows to local store where supported.
            if ($column === 'store_id' && $table !== 'stores') {
                $payload[$column] = $localStoreId;

                continue;
            }

            if ($table === 'stores' && in_array($column, self::STORE_REGION_FOREIGN_KEYS, true)) {
                continue;
            }

            $serverForeignId = (int) $payload[$column];

            if (! $this->cachedHasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $column)) {
                    $payload[$column] = null;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            // Fast path: pre-fetched chunk cache.
            $localId = null;
            if ($fkCache !== null && isset($fkCache[$column][$serverForeignId])) {
                $localId = $fkCache[$column][$serverForeignId];
            } elseif ($fkCache === null) {
                // Slow path: per-row SELECT (kept for callers outside the
                // bootstrap installer that don't pre-fetch).
                $query = DB::table($relatedTable)->where('server_id', $serverForeignId);
                if ($this->cachedHasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                    $query->where('store_id', $localStoreId);
                }
                $localId = $query->value('id');
            }

            if (is_numeric($localId)) {
                $payload[$column] = (int) $localId;
            } else {
                if ($this->isNullableColumn($table, $column)) {
                    $payload[$column] = null;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }
            }
        }

        return [
            'payload' => $payload,
            'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys,
        ];
    }

    /**
     * @param  array<string, array<string, array<int, int>>>|null  $morphCache
     *                                                                          optional pre-fetched map of prefix => relatedTable => (server_id => local_id).
     */
    private function mapMorphRelationsToLocalIds(string $table, array $payload, int $localStoreId, ?array $morphCache = null): array
    {
        $hasUnresolvedForeignKeys = false;

        foreach (['transactionable', 'referenceable', 'payable', 'imageable', 'activityable'] as $prefix) {
            $typeColumn = "{$prefix}_type";
            $idColumn = "{$prefix}_id";

            $type = trim((string) ($payload[$typeColumn] ?? ''));
            $remoteId = $payload[$idColumn] ?? null;

            if ($type === '' || ! is_numeric($remoteId)) {
                continue;
            }

            $morphDefinition = self::MORPH_TYPE_MAP[$type] ?? null;
            if (! is_array($morphDefinition)) {
                continue;
            }

            $relatedTable = (string) ($morphDefinition['table'] ?? '');
            // Canonicalize to the morph ALIAS Eloquent will write for newly
            // created local rows (e.g. 'App\\Models\\Customer'), not the
            // FQCN. Storing the FQCN here breaks any later query that filters
            // by `transactionable_type` against rows freshly created on the
            // POS — TransactionObserver's running-balance lookup misses every
            // bootstrapped row, so credit-sale balances start from 0 instead
            // of including the customer's pre-existing debt.
            $canonicalClass = $this->resolveMorphAlias(
                (string) ($morphDefinition['class'] ?? ''),
                $type,
            );

            if ($relatedTable === '' || ! $this->cachedHasTable($relatedTable) || ! $this->cachedHasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $idColumn)) {
                    $payload[$idColumn] = null;
                    $payload[$typeColumn] = $canonicalClass;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            // Fast path: pre-fetched chunk cache.
            $localId = null;
            if ($morphCache !== null && isset($morphCache[$prefix][$relatedTable][(int) $remoteId])) {
                $localId = $morphCache[$prefix][$relatedTable][(int) $remoteId];
            } elseif ($morphCache === null) {
                $query = DB::table($relatedTable)->where('server_id', (int) $remoteId);
                if ($this->cachedHasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                    $query->where('store_id', $localStoreId);
                }
                $localId = $query->value('id');
            }

            if (is_numeric($localId)) {
                $payload[$idColumn] = (int) $localId;
                $payload[$typeColumn] = $canonicalClass;
            } elseif ($table === 'model_activities' && $prefix === 'activityable') {
                $payload[$typeColumn] = $canonicalClass;
            } elseif ($this->isNullableColumn($table, $idColumn)) {
                $payload[$idColumn] = null;
                $payload[$typeColumn] = $canonicalClass;
            } else {
                $hasUnresolvedForeignKeys = true;
            }
        }

        return [
            'payload' => $payload,
            'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys,
        ];
    }

    /**
     * @return array{country_id:int|null,currency_id:int|null,timezone_id:int|null}
     */
    private function resolveLocalRegionReferences(array $serverStore): array
    {
        $countryId = null;
        $currencyId = null;
        $timezoneId = null;

        $countryCode = strtoupper(trim((string) data_get($serverStore, 'country.code', '')));
        $countryName = trim((string) data_get($serverStore, 'country.name', ''));
        if ($countryCode !== '') {
            $countryId = Country::query()
                ->whereRaw('UPPER(code) = ?', [$countryCode])
                ->value('id');
        } elseif ($countryName !== '') {
            $countryId = Country::query()
                ->where('name', $countryName)
                ->value('id');
        }

        $currencyCode = strtoupper(trim((string) data_get($serverStore, 'currency.code', '')));
        if ($currencyCode !== '') {
            $currencyId = Currency::query()
                ->whereRaw('UPPER(code) = ?', [$currencyCode])
                ->value('id');
        }

        $timezoneName = trim((string) data_get($serverStore, 'timezone.name', ''));
        if ($timezoneName !== '') {
            $timezoneId = Timezone::query()
                ->where('name', $timezoneName)
                ->value('id');
        }

        return [
            'country_id' => is_numeric($countryId) ? (int) $countryId : null,
            'currency_id' => is_numeric($currencyId) ? (int) $currencyId : null,
            'timezone_id' => is_numeric($timezoneId) ? (int) $timezoneId : null,
        ];
    }

    private function resolveNaturalUniqueLookup(string $table, array $payload): array
    {
        $keys = self::NATURAL_UNIQUE_KEYS[$table] ?? null;
        if (! is_array($keys)) {
            return [];
        }

        $lookup = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                return [];
            }

            if ($payload[$key] === null) {
                return [];
            }

            if (is_string($payload[$key]) && trim($payload[$key]) === '') {
                return [];
            }

            $lookup[$key] = $payload[$key];
        }

        return $lookup;
    }

    /**
     * Return the morph alias Eloquent will write for the given canonical
     * class, falling back to the incoming type if the class can't be
     * instantiated. This guarantees that the `*_type` string we persist
     * matches what `Model::getMorphClass()` produces for a freshly-created
     * local row — so subsequent `where('transactionable_type', …)` queries
     * (e.g. TransactionObserver's running-balance lookup) hit both the
     * bootstrapped rows and the new ones.
     */
    private function resolveMorphAlias(string $canonicalClass, string $fallback): string
    {
        if ($canonicalClass !== '' && class_exists($canonicalClass)) {
            try {
                $instance = new $canonicalClass;
                if ($instance instanceof Model) {
                    return $instance->getMorphClass();
                }
            } catch (\Throwable) {
                // Fall through to the FQCN fallback below.
            }

            return $canonicalClass;
        }

        return $fallback;
    }

    /**
     * Walk every per-resource cursor returned in a /delta response and
     * compute the "next iteration" cursor (oldest seen — safe for
     * pagination) plus the "max seen" cursor (for persistence as
     * last_delta_pull_at).
     *
     * @param  array<int, array<string, mixed>>  $resourcesPayload
     * @return array{next_since:?string,next_since_id:int,max_since:?string,max_since_id:int}
     */
    private function resolveDeltaPaginationCursors(array $resourcesPayload): array
    {
        $minSince = null;
        $minSinceId = 0;
        $maxSince = null;
        $maxSinceId = 0;

        foreach ($resourcesPayload as $resource) {
            if (! is_array($resource)) {
                continue;
            }

            $cursor = (array) ($resource['cursor'] ?? []);
            $updatedAt = $cursor['updated_at'] ?? null;
            $id = $cursor['id'] ?? null;

            if (! is_string($updatedAt) || $updatedAt === '') {
                continue;
            }

            $updatedAtTs = strtotime($updatedAt);
            if ($updatedAtTs === false) {
                continue;
            }

            $idInt = is_numeric($id) ? (int) $id : 0;

            // Oldest cursor → safe `next_since` for the following iteration:
            // resources that finished early will get re-walked from this
            // point, but their upserts are idempotent.
            if ($minSince === null || $updatedAtTs < strtotime((string) $minSince)) {
                $minSince = $updatedAt;
                $minSinceId = $idInt;
            }

            // Newest cursor → what we persist as `last_delta_pull_at` so
            // the NEXT sync skips everything we've already pulled.
            if ($maxSince === null || $updatedAtTs > strtotime((string) $maxSince)) {
                $maxSince = $updatedAt;
                $maxSinceId = $idInt;
            }
        }

        return [
            'next_since' => $minSince,
            'next_since_id' => $minSinceId,
            'max_since' => $maxSince,
            'max_since_id' => $maxSinceId,
        ];
    }

    /**
     * Cached wrapper around Schema::hasTable. PRAGMA-heavy on SQLite.
     */
    private function cachedHasTable(string $table): bool
    {
        if (array_key_exists($table, $this->hasTableCache)) {
            return $this->hasTableCache[$table];
        }

        return $this->hasTableCache[$table] = Schema::hasTable($table);
    }

    /**
     * Cached wrapper around Schema::hasColumn. PRAGMA-heavy on SQLite.
     */
    private function cachedHasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (array_key_exists($key, $this->hasColumnCache)) {
            return $this->hasColumnCache[$key];
        }

        return $this->hasColumnCache[$key] = Schema::hasColumn($table, $column);
    }

    /**
     * Cached wrapper around Schema::getColumnListing.
     *
     * @return list<string>
     */
    private function cachedColumnListing(string $table): array
    {
        if (array_key_exists($table, $this->columnListingCache)) {
            return $this->columnListingCache[$table];
        }

        return $this->columnListingCache[$table] = Schema::getColumnListing($table);
    }

    /**
     * Pre-fetch local IDs for every (related_table, server_id) pair
     * referenced by `$rows` so that mapForeignKeysToLocalIds can resolve
     * each FK with an O(1) array lookup instead of a per-row SELECT.
     *
     * Returns a structure shaped like:
     *   ['customer_id' => ['customers' => [<server_id> => <local_id>, …]], …]
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>> column => (server_id => local_id)
     */
    private function prefetchForeignKeyMap(string $table, array $rows, int $localStoreId): array
    {
        $idsByRelatedTable = [];
        $columnsToCheck = [];

        foreach (self::FOREIGN_KEY_TABLE_MAP as $column => $relatedTable) {
            if ($column === 'store_id' && $table !== 'stores') {
                continue;
            }
            if ($table === 'stores' && in_array($column, self::STORE_REGION_FOREIGN_KEYS, true)) {
                continue;
            }
            if (! $this->cachedHasTable($relatedTable) || ! $this->cachedHasColumn($relatedTable, 'server_id')) {
                continue;
            }
            $columnsToCheck[$column] = $relatedTable;
        }

        if ($columnsToCheck === []) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($columnsToCheck as $column => $relatedTable) {
                if (! array_key_exists($column, $row) || ! is_numeric($row[$column])) {
                    continue;
                }
                $idsByRelatedTable[$relatedTable][(int) $row[$column]] = true;
            }
        }

        $localByRelated = [];

        foreach ($idsByRelatedTable as $relatedTable => $idMap) {
            $serverIds = array_keys($idMap);
            if ($serverIds === []) {
                continue;
            }

            $query = DB::table($relatedTable)
                ->whereIn('server_id', $serverIds)
                ->select('id', 'server_id');

            if ($this->cachedHasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                $query->where('store_id', $localStoreId);
            }

            foreach ($query->get() as $resolved) {
                $localByRelated[$relatedTable][(int) $resolved->server_id] = (int) $resolved->id;
            }
        }

        $byColumn = [];
        foreach ($columnsToCheck as $column => $relatedTable) {
            $byColumn[$column] = $localByRelated[$relatedTable] ?? [];
        }

        return $byColumn;
    }

    /**
     * Same idea as prefetchForeignKeyMap, but for the polymorphic
     * `*_type` / `*_id` columns. Groups remote IDs by their resolved
     * related table so we only issue one query per related table per
     * chunk instead of one per row.
     *
     * Returns:
     *   ['transactionable' => ['customers' => [<server_id> => <local_id>, …]], …]
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, array<int, int>>>
     */
    private function prefetchMorphMap(string $table, array $rows, int $localStoreId): array
    {
        $idsByPrefixAndRelated = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (['transactionable', 'referenceable', 'payable', 'imageable', 'activityable'] as $prefix) {
                $type = trim((string) ($row["{$prefix}_type"] ?? ''));
                $remoteId = $row["{$prefix}_id"] ?? null;
                if ($type === '' || ! is_numeric($remoteId)) {
                    continue;
                }

                $morphDefinition = self::MORPH_TYPE_MAP[$type] ?? null;
                if (! is_array($morphDefinition)) {
                    continue;
                }

                $relatedTable = (string) ($morphDefinition['table'] ?? '');
                if ($relatedTable === '' || ! $this->cachedHasTable($relatedTable) || ! $this->cachedHasColumn($relatedTable, 'server_id')) {
                    continue;
                }

                $idsByPrefixAndRelated[$prefix][$relatedTable][(int) $remoteId] = true;
            }
        }

        $localByPrefixAndRelated = [];

        foreach ($idsByPrefixAndRelated as $prefix => $byRelated) {
            foreach ($byRelated as $relatedTable => $idMap) {
                $serverIds = array_keys($idMap);
                if ($serverIds === []) {
                    continue;
                }

                $query = DB::table($relatedTable)
                    ->whereIn('server_id', $serverIds)
                    ->select('id', 'server_id');

                if ($this->cachedHasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                    $query->where('store_id', $localStoreId);
                }

                foreach ($query->get() as $resolved) {
                    $localByPrefixAndRelated[$prefix][$relatedTable][(int) $resolved->server_id] = (int) $resolved->id;
                }
            }
        }

        return $localByPrefixAndRelated;
    }

    private function isNullableColumn(string $table, string $column): bool
    {
        $cacheKey = "{$table}.{$column}";
        if (array_key_exists($cacheKey, $this->nullableColumnCache)) {
            return $this->nullableColumnCache[$cacheKey];
        }

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            $this->nullableColumnCache[$cacheKey] = false;

            return false;
        }

        $columns = Schema::getColumns($table);
        foreach ($columns as $definition) {
            if (($definition['name'] ?? null) === $column) {
                $isNullable = (bool) ($definition['nullable'] ?? false);
                $this->nullableColumnCache[$cacheKey] = $isNullable;

                return $isNullable;
            }
        }

        $this->nullableColumnCache[$cacheKey] = false;

        return false;
    }

    private function mapLocalForeignKeysToServerIds(string $table, array $payload): array
    {
        $hasUnresolvedForeignKeys = false;

        foreach (self::FOREIGN_KEY_TABLE_MAP as $column => $relatedTable) {
            if (! array_key_exists($column, $payload) || ! is_numeric($payload[$column])) {
                continue;
            }

            if ($column === 'store_id' && $table !== 'stores') {
                continue;
            }

            if ($table === 'stores' && in_array($column, self::STORE_REGION_FOREIGN_KEYS, true)) {
                $payload[$column] = null;

                continue;
            }

            if (! Schema::hasTable($relatedTable)) {
                continue;
            }

            $localForeignId = (int) $payload[$column];

            if (! Schema::hasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $column)) {
                    $payload[$column] = null;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            $query = DB::table($relatedTable)->where('id', $localForeignId);
            $serverForeignId = $query->value('server_id');

            if (is_numeric($serverForeignId)) {
                $payload[$column] = (int) $serverForeignId;
            } else {
                if ($this->isNullableColumn($table, $column)) {
                    $payload[$column] = null;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }
            }
        }

        return [
            'payload' => $payload,
            'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys,
        ];
    }

    private function mapLocalMorphRelationsToServerIds(string $table, array $payload): array
    {
        $hasUnresolvedForeignKeys = false;

        foreach (['transactionable', 'referenceable', 'payable', 'imageable', 'activityable'] as $prefix) {
            $typeColumn = "{$prefix}_type";
            $idColumn = "{$prefix}_id";

            $type = trim((string) ($payload[$typeColumn] ?? ''));
            $localId = $payload[$idColumn] ?? null;

            if ($type === '' || ! is_numeric($localId)) {
                continue;
            }

            $morphDefinition = self::MORPH_TYPE_MAP[$type] ?? null;
            if (! is_array($morphDefinition)) {
                continue;
            }

            $relatedTable = (string) ($morphDefinition['table'] ?? '');
            // See mapMorphRelationsToLocalIds: canonicalize to the morph
            // alias Eloquent uses, not the FQCN, so that round-tripping
            // bootstrap→edit→push keeps the same `*_type` string.
            $canonicalClass = $this->resolveMorphAlias(
                (string) ($morphDefinition['class'] ?? ''),
                $type,
            );

            if ($relatedTable === '' || ! Schema::hasTable($relatedTable) || ! Schema::hasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $idColumn)) {
                    $payload[$idColumn] = null;
                    $payload[$typeColumn] = $canonicalClass;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            $serverId = DB::table($relatedTable)
                ->where('id', (int) $localId)
                ->value('server_id');

            if (is_numeric($serverId)) {
                $payload[$idColumn] = (int) $serverId;
                $payload[$typeColumn] = $canonicalClass;
            } elseif ($this->isNullableColumn($table, $idColumn)) {
                $payload[$idColumn] = null;
                $payload[$typeColumn] = $canonicalClass;
            } else {
                $hasUnresolvedForeignKeys = true;
            }
        }

        return [
            'payload' => $payload,
            'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys,
        ];
    }

    private function updateLocalRowSyncStatus(string $table, object $row, array $updates): void
    {
        $query = DB::table($table);

        if (property_exists($row, 'id') && is_numeric($row->id) && Schema::hasColumn($table, 'id')) {
            $query->where('id', (int) $row->id)->update($updates);

            return;
        }

        if ($table === 'sale_variation') {
            $query
                ->where('sale_id', (int) ($row->sale_id ?? 0))
                ->where('variation_id', $row->variation_id ?? null)
                ->where('stock_id', $row->stock_id ?? null)
                ->where('is_preparable', (int) ($row->is_preparable ?? 0))
                ->where('quantity', $row->quantity ?? 0)
                ->where('unit_price', $row->unit_price ?? 0)
                ->update($updates);
        }
    }

    /**
     * Flip a pushed row to `synced` ONLY when its `updated_at` is unchanged
     * since we read it for the push. This is the compare-and-swap that
     * prevents the push job from clobbering a fresh local edit that landed
     * between the push's SELECT and its post-success status update.
     *
     * If the row was re-edited mid-push, we leave sync_state='pending' and
     * let the next push round pick up the new version. The server already
     * has the OLD version, but the next push will overwrite it with the new
     * one — net result: no lost local edit.
     *
     * Returns true when the flip happened, false when the row was re-edited
     * and we left it pending.
     */
    private function flipRowToSyncedIfUnchanged(string $table, object $row, array $updates): bool
    {
        if (! property_exists($row, 'id') || ! is_numeric($row->id) || ! Schema::hasColumn($table, 'id')) {
            // No usable PK → fall through to the unguarded update so the
            // sale_variation no-PK case keeps working.
            $this->updateLocalRowSyncStatus($table, $row, $updates);

            return true;
        }

        if (! Schema::hasColumn($table, 'updated_at') || ! property_exists($row, 'updated_at')) {
            // No timestamp to guard on → behave as before.
            $this->updateLocalRowSyncStatus($table, $row, $updates);

            return true;
        }

        $affected = (int) DB::table($table)
            ->where('id', (int) $row->id)
            ->where('updated_at', $row->updated_at)
            ->update($updates);

        return $affected > 0;
    }

    private function applyDefaultOrder(Builder $query, string $table): void
    {
        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');

            return;
        }

        if (Schema::hasColumn($table, 'created_at')) {
            $query->orderBy('created_at');

            return;
        }

        if (Schema::hasColumn($table, 'local_id')) {
            $query->orderBy('local_id');
        }
    }

    private function resolveStoreIdForPayload(string $table, array $payload, int $defaultStoreId): int
    {
        if (isset($payload['store_id']) && is_numeric($payload['store_id'])) {
            return (int) $payload['store_id'];
        }

        if (($table === 'sale_variation' || $table === 'sale_preparable_items') && isset($payload['sale_id']) && is_numeric($payload['sale_id'])) {
            $saleStoreId = DB::table('sales')->where('id', (int) $payload['sale_id'])->value('store_id');
            if (is_numeric($saleStoreId)) {
                return (int) $saleStoreId;
            }
        }

        return $defaultStoreId;
    }

    private function resolveCompositeLookupForPulledRow(string $table, array $payload): array
    {
        if ($table === 'sale_variation') {
            if (! isset($payload['sale_id'])) {
                return [];
            }

            if (isset($payload['variation_id']) && $payload['variation_id'] !== null && $payload['variation_id'] !== '') {
                if (! empty($payload['stock_id'])) {
                    return [
                        'sale_id' => (int) $payload['sale_id'],
                        'variation_id' => $payload['variation_id'],
                        'stock_id' => $payload['stock_id'],
                        'is_preparable' => (int) ($payload['is_preparable'] ?? 0),
                    ];
                }

                return [
                    'sale_id' => (int) $payload['sale_id'],
                    'variation_id' => $payload['variation_id'],
                ];
            }

            if (filled($payload['local_id'] ?? null)) {
                return [
                    'sale_id' => (int) $payload['sale_id'],
                    'local_id' => (string) $payload['local_id'],
                ];
            }

            return [
                'sale_id' => (int) $payload['sale_id'],
                'variation_id' => null,
                'stock_id' => null,
                'is_preparable' => (int) ($payload['is_preparable'] ?? 0),
                'description' => (string) ($payload['description'] ?? ''),
                'unit_price' => $payload['unit_price'] ?? 0,
            ];
        }

        if ($table === 'sale_preparable_items') {
            if (! isset($payload['sale_id'], $payload['sequence'], $payload['preparable_variation_id'], $payload['variation_id'])) {
                return [];
            }

            return [
                'sale_id' => (int) $payload['sale_id'],
                'sequence' => (int) $payload['sequence'],
                'preparable_variation_id' => (int) $payload['preparable_variation_id'],
                'variation_id' => (int) $payload['variation_id'],
                'stock_id' => isset($payload['stock_id']) && is_numeric($payload['stock_id']) ? (int) $payload['stock_id'] : null,
            ];
        }

        return [];
    }

    /**
     * Verify the outbound payload carries something the server can match a
     * row by. Defensive last-mile check before POSTing to /delta/upsert so
     * a malformed row can never accidentally create a duplicate on the
     * server.
     */
    private function payloadHasUsableIdentifier(string $resource, array $payload): bool
    {
        if (is_numeric($payload['server_id'] ?? null) && (int) $payload['server_id'] > 0) {
            return true;
        }

        if (filled($payload['local_id'] ?? null)) {
            return true;
        }

        // Tables that match by natural composite key are fine without
        // server_id/local_id — the server uses the same key to look them up.
        return $this->resolveCompositeLookupForPulledRow($resource, $payload) !== [];
    }
}
