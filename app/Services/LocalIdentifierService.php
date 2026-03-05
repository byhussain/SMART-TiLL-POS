<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocalIdentifierService
{
    public function __construct(private readonly DeviceIdentifierService $deviceIdentifierService) {}

    public function makeForNumericId(int $id): string
    {
        return $this->deviceIdentifierService->getPrefix().'-'.$id;
    }

    public function makeForTable(string $table, ?int $storeId): string
    {
        $prefix = $this->deviceIdentifierService->getPrefix();
        $storeIdValue = max(0, (int) ($storeId ?? 0));
        $entityType = trim($table) !== '' ? $table : '*';
        $nextNumber = $this->nextSequence($prefix, $storeIdValue, $entityType);
        $localId = $prefix.'-'.$nextNumber;

        // Ensure uniqueness for this table/store in case of legacy collisions.
        while ($this->localIdExists($table, $localId, $storeIdValue)) {
            $nextNumber = $this->nextSequence($prefix, $storeIdValue, $entityType);
            $localId = $prefix.'-'.$nextNumber;
        }

        return $localId;
    }

    public function ensureForModel(Model $model): ?string
    {
        $table = $model->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'local_id')) {
            return null;
        }

        $currentLocalId = (string) ($model->getAttribute('local_id') ?? '');
        if (trim($currentLocalId) !== '') {
            return $currentLocalId;
        }

        $key = $model->getKey();
        if (! is_numeric($key)) {
            return null;
        }

        $storeId = $this->resolveStoreIdForModel($model);
        $localId = $this->makeForTable($table, $storeId > 0 ? $storeId : null);
        DB::table($table)
            ->where('id', (int) $key)
            ->update(['local_id' => $localId]);
        $model->setAttribute('local_id', $localId);

        return $localId;
    }

    private function resolveStoreIdForModel(Model $model): int
    {
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'store_id')) {
            return (int) ($model->getAttribute('store_id') ?? 0);
        }

        return match ($table) {
            'product_attributes' => (int) DB::table('products')
                ->where('id', (int) $model->getAttribute('product_id'))
                ->value('store_id'),
            'purchase_order_products' => (int) DB::table('purchase_orders')
                ->where('id', (int) $model->getAttribute('purchase_order_id'))
                ->value('store_id'),
            'sale_variation', 'sale_preparable_items' => (int) DB::table('sales')
                ->where('id', (int) $model->getAttribute('sale_id'))
                ->value('store_id'),
            'stocks' => (int) DB::table('variations')
                ->where('id', (int) $model->getAttribute('variation_id'))
                ->value('store_id'),
            default => 0,
        };
    }

    private function nextSequence(string $prefix, int $storeId, string $entityType): int
    {
        $this->ensureSequenceTableExists();

        return DB::transaction(function () use ($prefix, $storeId, $entityType): int {
            $query = DB::table('local_id_sequences')
                ->where('prefix', $prefix)
                ->where('store_id', $storeId);

            if (Schema::hasColumn('local_id_sequences', 'entity_type')) {
                $query->where('entity_type', $entityType);
            }

            $current = $query->lockForUpdate()->first();

            if ($current === null) {
                $insert = [
                    'prefix' => $prefix,
                    'store_id' => $storeId,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('local_id_sequences', 'entity_type')) {
                    $insert['entity_type'] = $entityType;
                }

                DB::table('local_id_sequences')->insert($insert);

                return 1;
            }

            $next = (int) $current->last_number + 1;

            DB::table('local_id_sequences')
                ->where('id', (int) $current->id)
                ->update([
                    'last_number' => $next,
                    'updated_at' => now(),
                ]);

            return $next;
        }, 3);
    }

    private function ensureSequenceTableExists(): void
    {
        if (Schema::hasTable('local_id_sequences')) {
            if (! Schema::hasColumn('local_id_sequences', 'entity_type')) {
                Schema::table('local_id_sequences', function (Blueprint $table): void {
                    $table->string('entity_type', 64)->default('*')->after('store_id');
                });
            }

            return;
        }

        Schema::create('local_id_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 16);
            $table->unsignedBigInteger('store_id')->default(0);
            $table->string('entity_type', 64)->default('*');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['prefix', 'store_id', 'entity_type'], 'local_id_sequences_prefix_store_entity_unique');
        });
    }

    private function localIdExists(string $table, string $localId, int $storeId): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'local_id')) {
            return false;
        }

        $query = DB::table($table)->where('local_id', $localId);

        if (Schema::hasColumn($table, 'store_id') && $storeId > 0) {
            $query->where('store_id', $storeId);
        }

        return $query->exists();
    }
}
