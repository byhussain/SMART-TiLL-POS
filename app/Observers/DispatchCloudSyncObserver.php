<?php

namespace App\Observers;

use App\Jobs\SyncCloudStoreData;
use App\Models\Store;
use App\Services\LocalIdentifierService;
use App\Services\RuntimeStateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\ProductAttribute;
use SmartTill\Core\Models\PurchaseOrderProduct;
use SmartTill\Core\Models\SalePreparableItem;
use SmartTill\Core\Models\SaleVariation;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\StoreSetting;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Models\UnitDimension;
use SmartTill\Core\Models\Variation;

class DispatchCloudSyncObserver
{
    public function __construct(private readonly LocalIdentifierService $localIdentifierService) {}

    public function created(Model $model): void
    {
        $this->dispatchForModel($model);
    }

    public function updated(Model $model): void
    {
        $this->dispatchForModel($model);
    }

    public function deleted(Model $model): void
    {
        $this->dispatchForModel($model);
    }

    private function dispatchForModel(Model $model): void
    {
        $this->ensureServerManagedReference($model);
        $this->localIdentifierService->ensureForModel($model);

        $runtimeState = app(RuntimeStateService::class)->get();
        if ($runtimeState->mode !== 'cloud' || ! $runtimeState->cloud_token_present) {
            return;
        }

        $storeId = $this->resolveStoreId($model);
        if ($storeId <= 0) {
            return;
        }

        $store = Store::query()->find($storeId);
        if (! $store || (int) ($store->server_id ?? 0) <= 0) {
            return;
        }

        $resource = $this->resolveResource($model);
        if ($resource === null) {
            return;
        }

        SyncCloudStoreData::dispatch($storeId, 'delta', null, $resource);
    }

    private function ensureServerManagedReference(Model $model): void
    {
        $table = $model->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reference')) {
            return;
        }

        if (! Schema::hasColumn($table, 'server_id')) {
            return;
        }

        $serverId = (int) ($model->getAttribute('server_id') ?? 0);
        if ($serverId > 0) {
            return;
        }

        $reference = trim((string) ($model->getAttribute('reference') ?? ''));
        if ($reference === '') {
            return;
        }

        $key = $model->getKey();
        if (! is_numeric($key)) {
            return;
        }

        DB::table($table)
            ->where('id', (int) $key)
            ->update(['reference' => null]);

        $model->setAttribute('reference', null);
    }

    private function resolveStoreId(Model $model): int
    {
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'store_id')) {
            return (int) ($model->getAttribute('store_id') ?? 0);
        }

        if ($model instanceof ProductAttribute) {
            return (int) DB::table('products')
                ->where('id', (int) $model->getAttribute('product_id'))
                ->value('store_id');
        }

        if ($model instanceof PurchaseOrderProduct) {
            return (int) DB::table('purchase_orders')
                ->where('id', (int) $model->getAttribute('purchase_order_id'))
                ->value('store_id');
        }

        if ($model instanceof SaleVariation || $model instanceof SalePreparableItem) {
            return (int) DB::table('sales')
                ->where('id', (int) $model->getAttribute('sale_id'))
                ->value('store_id');
        }

        if ($model instanceof Stock) {
            return (int) DB::table('variations')
                ->where('id', (int) $model->getAttribute('variation_id'))
                ->value('store_id');
        }

        return 0;
    }

    private function resolveResource(Model $model): ?string
    {
        return match (true) {
            $model instanceof StoreSetting => null,
            $model->getTable() === 'sales' => 'sales',
            $model instanceof SaleVariation => 'sale_variation',
            $model instanceof SalePreparableItem => 'sale_preparable_items',
            $model->getTable() === 'customers' => 'customers',
            $model->getTable() === 'payments' => 'payments',
            $model instanceof Transaction => 'transactions',
            $model->getTable() === 'products' => 'products',
            $model instanceof ProductAttribute => 'product_attributes',
            $model instanceof Variation => 'variations',
            $model instanceof Stock => 'stocks',
            $model->getTable() === 'brands' => 'brands',
            $model->getTable() === 'categories' => 'categories',
            $model->getTable() === 'attributes' => 'attributes',
            $model->getTable() === 'units' => 'units',
            $model instanceof UnitDimension => 'unit_dimensions',
            $model->getTable() === 'purchase_orders' => 'purchase_orders',
            $model instanceof PurchaseOrderProduct => 'purchase_order_products',
            $model->getTable() === 'suppliers' => 'suppliers',
            default => null,
        };
    }
}
