<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SyncOutbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    private const PULL_PER_PAGE = 200;

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
            return $this->runBootstrapSync($baseUrl, $token, $localStore);
        }

        return $this->runDeltaSync($baseUrl, $token, $localStore, $module);
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
        $runtimeStateService->markBootstrapStarted((int) $localStore->id, $generation, 'Downloading store data');

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
    public function runPushOnly(
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

        $resources = $resource !== null
            ? [$resource]
            : ($module !== null ? $this->resolveResourcesForModule($module) : self::PULL_ORDER);

        if ($module !== null && $resources === []) {
            return ['ok' => false, 'message' => 'Invalid sync module selected.'];
        }

        $pushed = $this->pushPendingRowsV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);

        return [
            'ok' => true,
            'mode' => 'push',
            'module' => $module,
            'resource' => $resource,
            'pushed' => $pushed,
        ];
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

        $deltaResponse = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(0)
            ->get("/api/pos/v2/stores/{$serverStoreId}/delta", array_filter([
                'since' => is_string($since) && $since !== '' ? $since : null,
                'resource' => $resource,
            ]));

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

        $pulled = $this->applyDeltaResources((array) ($deltaPayload['data'] ?? []), (int) $localStore->id);
        $runtimeStateService->markDeltaPulled((int) $localStore->id);
        $pushed = $this->pushPendingRowsV2($baseUrl, $token, $serverStoreId, (int) $localStore->id, $resources);
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
            $this->applyTombstones($resource, $tombstones, $localStoreId);
            if ($deferredRows !== []) {
                $deferredRowsByResource[$resource] = [
                    ...($deferredRowsByResource[$resource] ?? []),
                    ...$deferredRows,
                ];
            }
        }

        if ($deferredRowsByResource !== []) {
            $this->retryDeferredPullRows($deferredRowsByResource, $localStoreId);
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

    private function applyTombstones(string $resource, array $tombstones, int $localStoreId): void
    {
        if (! Schema::hasTable($resource)) {
            return;
        }

        foreach ($tombstones as $tombstone) {
            if (! is_array($tombstone)) {
                continue;
            }

            $localRow = $this->findExistingLocalRow($resource, $tombstone, $localStoreId);
            if ($localRow === null || $this->shouldSkipPulledRow($resource, $localRow, $localStoreId)) {
                continue;
            }

            $updates = [];
            if (Schema::hasColumn($resource, 'deleted_at') && array_key_exists('deleted_at', $tombstone)) {
                $updates['deleted_at'] = $tombstone['deleted_at'];
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
    }

    private function pushPendingRowsV2(string $baseUrl, string $token, int $serverStoreId, int $localStoreId, array $resources = []): int
    {
        $resourceList = $resources !== [] ? $resources : self::PULL_ORDER;
        $pushedCount = 0;

        foreach ($resourceList as $resource) {
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

                    $this->updateLocalRowSyncStatus($resource, $row, $updates);
                    $pushedCount++;
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

    private function shouldSkipPulledRow(string $table, object $localRow, int $localStoreId): bool
    {
        if (property_exists($localRow, 'sync_state') && in_array((string) ($localRow->sync_state ?? ''), ['pending', 'failed'], true)) {
            return true;
        }

        $localId = (int) ($localRow->id ?? 0);
        if ($localId <= 0) {
            return false;
        }

        return SyncOutbox::query()
            ->where('entity_type', $table)
            ->where('local_id', $localId)
            ->whereIn('status', ['pending', 'failed'])
            ->exists();
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
        if (! Schema::hasTable($table) || empty($rows)) {
            return [];
        }

        $columns = collect(Schema::getColumnListing($table));
        $deferredRows = [];

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

            ['payload' => $normalized, 'has_unresolved_foreign_keys' => $hasUnresolvedForeignKeys] = $this->mapForeignKeysToLocalIds($table, $normalized, $localStoreId);
            ['payload' => $normalized, 'has_unresolved_foreign_keys' => $hasUnresolvedMorphRelations] = $this->mapMorphRelationsToLocalIds($table, $normalized, $localStoreId);

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
            if ($existingLocalRow !== null && $this->shouldSkipPulledRow($table, $existingLocalRow, $localStoreId)) {
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
                } elseif (Schema::hasColumn($table, 'store_id') && $table !== 'stores' && isset($normalized['store_id'])) {
                    $existingByServerIdQuery->where('store_id', $normalized['store_id']);
                }

                if (Schema::hasColumn($table, 'id')) {
                    $existingByServerId = $existingByServerIdQuery->value('id');
                } else {
                    $existingByServerId = $existingByServerIdQuery->exists() ? 1 : null;
                }
            }

            $existingByLocalId = null;
            $existingByLocalIdQuery = null;
            if (Schema::hasColumn($table, 'local_id') && trim((string) ($normalized['local_id'] ?? '')) !== '') {
                $existingByLocalIdQuery = DB::table($table)
                    ->where('local_id', (string) $normalized['local_id']);

                if ($table === 'unit_dimensions' && isset($normalized['store_id'])) {
                    $existingByLocalIdQuery->where('store_id', $normalized['store_id']);
                } elseif (Schema::hasColumn($table, 'store_id') && $table !== 'stores' && isset($normalized['store_id'])) {
                    $existingByLocalIdQuery->where('store_id', $normalized['store_id']);
                }

                if (Schema::hasColumn($table, 'id')) {
                    $existingByLocalId = $existingByLocalIdQuery->value('id');
                } else {
                    $existingByLocalId = $existingByLocalIdQuery->exists() ? 1 : null;
                }
            }

            $naturalLookup = $this->resolveNaturalUniqueLookup($table, $normalized);
            if (! empty($naturalLookup)) {
                $existingNaturalQuery = DB::table($table)->where($naturalLookup);

                if (Schema::hasColumn($table, 'id')) {
                    $existingId = $existingNaturalQuery->value('id');

                    if (is_numeric($existingId)) {
                        if (
                            is_numeric($existingByServerId)
                            && (int) $existingByServerId !== (int) $existingId
                            && Schema::hasColumn($table, 'server_id')
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
                if (Schema::hasColumn($table, 'id')) {
                    DB::table($table)
                        ->where('id', (int) $existingByLocalId)
                        ->update($normalized);
                } elseif ($existingByLocalIdQuery !== null) {
                    $existingByLocalIdQuery->update($normalized);
                }

                continue;
            }

            if (is_numeric($existingByServerId)) {
                if (Schema::hasColumn($table, 'id')) {
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

        return $deferredRows;
    }

    private function mapForeignKeysToLocalIds(string $table, array $payload, int $localStoreId): array
    {
        $hasUnresolvedForeignKeys = false;

        foreach (self::FOREIGN_KEY_TABLE_MAP as $column => $relatedTable) {
            if (! array_key_exists($column, $payload) || ! is_numeric($payload[$column])) {
                continue;
            }

            if (! Schema::hasTable($relatedTable)) {
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

            $query = DB::table($relatedTable);
            if (! Schema::hasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $column)) {
                    $payload[$column] = null;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            $query->where('server_id', $serverForeignId);

            if (Schema::hasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                $query->where('store_id', $localStoreId);
            }

            $localId = $query->value('id');
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

    private function mapMorphRelationsToLocalIds(string $table, array $payload, int $localStoreId): array
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
            $canonicalClass = (string) ($morphDefinition['class'] ?? $type);

            if ($relatedTable === '' || ! Schema::hasTable($relatedTable) || ! Schema::hasColumn($relatedTable, 'server_id')) {
                if ($this->isNullableColumn($table, $idColumn)) {
                    $payload[$idColumn] = null;
                    $payload[$typeColumn] = $canonicalClass;
                } else {
                    $hasUnresolvedForeignKeys = true;
                }

                continue;
            }

            $query = DB::table($relatedTable)->where('server_id', (int) $remoteId);
            if (Schema::hasColumn($relatedTable, 'store_id') && $relatedTable !== 'stores') {
                $query->where('store_id', $localStoreId);
            }

            $localId = $query->value('id');
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
            $canonicalClass = (string) ($morphDefinition['class'] ?? $type);

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
}
