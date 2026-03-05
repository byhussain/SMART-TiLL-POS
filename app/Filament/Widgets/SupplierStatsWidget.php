<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Supplier;
use SmartTill\Core\Models\Transaction;

class SupplierStatsWidget extends BaseStatsOverviewWidget
{
    use FormatsCurrency;

    protected static ?int $sort = 3;

    protected int|array|null $columns = 3;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Supplier Stats Widget');
    }

    protected function getStore()
    {
        return Filament::getTenant();
    }

    protected function getStats(): array
    {
        $store = $this->getStore();

        // Get latest transactions for all suppliers in this store
        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where('transactionable_type', Supplier::class)
            ->whereIn('id', function ($query) use ($store) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->where('transactionable_type', Supplier::class)
                    ->groupBy('transactionable_id');
            })
            ->get();

        // Total pending amount (amount_balance < 0 means we owe supplier, convert to positive)
        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingAmountRaw = abs($latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') < 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance')));
        $pendingAmount = $this->convertFromStorage($pendingAmountRaw, $store);

        // Number of suppliers with pending amounts
        $suppliersWithPending = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') < 0)
            ->count();

        // Total number of suppliers
        $totalSuppliers = Supplier::query()
            ->where('store_id', $store->id)
            ->count();

        // Generate optimized charts for last 7 days using database aggregation
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily pending amounts using database aggregation (optimized)
        // Get latest transaction IDs per supplier first
        $latestTransactionIds = DB::table('transactions')
            ->selectRaw('MAX(id) as max_id')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Supplier::class)
            ->groupBy('transactionable_id')
            ->pluck('max_id');

        $dailyPendingAmounts = DB::table('transactions')
            ->whereIn('id', $latestTransactionIds)
            ->where('amount_balance', '<', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, ABS(SUM(amount_balance)) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $pendingAmountChart = $days->map(function ($date) use ($dailyPendingAmounts, $store) {
            $totalRaw = (float) ($dailyPendingAmounts[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        // Get daily supplier counts (optimized)
        $dailySupplierCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Supplier::class)
            ->where('amount_balance', '<', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $suppliersWithPendingChart = $days->map(function ($date) use ($dailySupplierCounts) {
            return (int) ($dailySupplierCounts[$date] ?? 0);
        })->toArray();

        // Get daily total supplier counts (optimized)
        $dailyTotalSupplierCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Supplier::class)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $totalSuppliersChart = $days->map(function ($date) use ($dailyTotalSupplierCounts) {
            return (int) ($dailyTotalSupplierCounts[$date] ?? 0);
        })->toArray();

        return [
            BaseStatsOverviewWidget\Stat::make('Total Pending Amount', $this->formatCompactCurrency($pendingAmount, $store))
                ->description('Amount to be paid to suppliers')
                ->color('warning')
                ->chart($pendingAmountChart),
            BaseStatsOverviewWidget\Stat::make('Suppliers with Pending', number_format($suppliersWithPending))
                ->description('Suppliers with pending balances')
                ->color('info')
                ->chart($suppliersWithPendingChart),
            BaseStatsOverviewWidget\Stat::make('Total Suppliers', number_format($totalSuppliers))
                ->description('All suppliers in store')
                ->color('success')
                ->chart($totalSuppliersChart),
        ];
    }
}
