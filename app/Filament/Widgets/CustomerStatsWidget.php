<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Transaction;

class CustomerStatsWidget extends BaseStatsOverviewWidget
{
    use FormatsCurrency;

    protected static ?int $sort = 2;

    protected int|array|null $columns = 3;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Customer Stats Widget');
    }

    protected function getStore()
    {
        return Filament::getTenant();
    }

    protected function getStats(): array
    {
        $store = $this->getStore();

        // Get latest transactions for all customers in this store
        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where('transactionable_type', Customer::class)
            ->whereIn('id', function ($query) use ($store) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->where('transactionable_type', Customer::class)
                    ->groupBy('transactionable_id');
            })
            ->get();

        // Total pending amount (amount_balance > 0 means customer owes us)
        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingAmountRaw = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') > 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance'));
        $pendingAmount = $this->convertFromStorage($pendingAmountRaw, $store);

        // Number of customers with pending amounts
        $customersWithPending = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') > 0)
            ->count();

        // Total number of customers
        $totalCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->count();

        // Generate optimized charts for last 7 days using database aggregation
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily pending amounts using database aggregation (optimized)
        // Get latest transaction IDs per customer first
        $latestTransactionIds = DB::table('transactions')
            ->selectRaw('MAX(id) as max_id')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Customer::class)
            ->groupBy('transactionable_id')
            ->pluck('max_id');

        $dailyPendingAmounts = DB::table('transactions')
            ->whereIn('id', $latestTransactionIds)
            ->where('amount_balance', '>', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, SUM(amount_balance) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $pendingAmountChart = $days->map(function ($date) use ($dailyPendingAmounts, $store) {
            $totalRaw = (float) ($dailyPendingAmounts[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        // Get daily customer counts (optimized)
        $dailyCustomerCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Customer::class)
            ->where('amount_balance', '>', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $customersWithPendingChart = $days->map(function ($date) use ($dailyCustomerCounts) {
            return (int) ($dailyCustomerCounts[$date] ?? 0);
        })->toArray();

        // Get daily total customer counts (optimized)
        $dailyTotalCustomerCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->where('transactionable_type', Customer::class)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $totalCustomersChart = $days->map(function ($date) use ($dailyTotalCustomerCounts) {
            return (int) ($dailyTotalCustomerCounts[$date] ?? 0);
        })->toArray();

        return [
            BaseStatsOverviewWidget\Stat::make('Total Pending Amount', $this->formatCompactCurrency($pendingAmount, $store))
                ->description('Amount to be received from customers')
                ->color('warning')
                ->chart($pendingAmountChart),
            BaseStatsOverviewWidget\Stat::make('Customers with Pending', number_format($customersWithPending))
                ->description('Customers with pending balances')
                ->color('info')
                ->chart($customersWithPendingChart),
            BaseStatsOverviewWidget\Stat::make('Total Customers', number_format($totalCustomers))
                ->description('All customers in store')
                ->color('success')
                ->chart($totalCustomersChart),
        ];
    }
}
