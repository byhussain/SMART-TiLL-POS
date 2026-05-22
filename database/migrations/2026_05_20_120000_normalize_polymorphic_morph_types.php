<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill: replace polymorphic `*_type` values stored as the
     * SmartTill\Core FQCN with the Laravel morph alias the local app
     * uses (`App\Models\…`). Before this fix CloudSyncService rewrote
     * the type to the FQCN on bootstrap, which broke any later query
     * filtering by `transactionable_type` against locally-created rows
     * — most visibly TransactionObserver's running-balance lookup,
     * which made new credit-sale balances start from 0 instead of
     * carrying forward the customer's pre-existing debt.
     *
     * Safe to re-run: idempotent UPDATEs. Tables/columns checked
     * before touching them so this is a no-op on installs that never
     * received the buggy bootstrap.
     */
    public function up(): void
    {
        /** @var array<string, string> $rewrites class FQCN => morph alias */
        $rewrites = [
            'SmartTill\Core\Models\Customer' => 'App\Models\Customer',
            'SmartTill\Core\Models\Supplier' => 'App\Models\Supplier',
            'SmartTill\Core\Models\Sale' => 'App\Models\Sale',
            'SmartTill\Core\Models\PurchaseOrder' => 'App\Models\PurchaseOrder',
            'SmartTill\Core\Models\Payment' => 'App\Models\Payment',
        ];

        /** @var array<string, list<string>> $tableColumns table => list of morph type columns */
        $tableColumns = [
            'transactions' => ['transactionable_type', 'referenceable_type'],
            'payments' => ['payable_type', 'referenceable_type'],
            'images' => ['imageable_type'],
            'model_activities' => ['activityable_type'],
        ];

        foreach ($tableColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($rewrites as $fqcn => $alias) {
                    DB::table($table)
                        ->where($column, $fqcn)
                        ->update([$column => $alias]);
                }
            }
        }
    }

    /**
     * No rollback. The original FQCN form was a bug; reverting would
     * re-introduce the credit-sale balance issue.
     */
    public function down(): void
    {
        //
    }
};
