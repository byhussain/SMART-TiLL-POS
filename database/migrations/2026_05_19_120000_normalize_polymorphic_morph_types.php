<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill: replace any polymorphic `*_type` values that were stored as
     * the SmartTill\Core FQCN with the Laravel morph alias the local app
     * uses (`App\Models\…`). Before this fix, CloudSyncService rewrote the
     * type to the FQCN on bootstrap, which broke any later query that
     * filtered by `transactionable_type` against locally-created rows —
     * most visibly TransactionObserver's running-balance lookup, which made
     * new credit-sale balances start from 0 instead of carrying forward the
     * customer's pre-existing debt.
     *
     * Safe to re-run: the UPDATEs are idempotent. Tables/columns are
     * checked before touching them so this migration is a no-op on
     * installs that never received the buggy bootstrap.
     */
    public function up(): void
    {
        /**
         * @var array<string, string> $rewrites class FQCN => morph alias
         *
         * Only the classes that CoreServiceProvider's morphMap aliases to
         * `App\Models\*` are listed here. Variation/Product are intentionally
         * absent — they have no App\Models\* alias, so Eloquent stores the
         * FQCN for them and the bootstrapped rows already match.
         */
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
