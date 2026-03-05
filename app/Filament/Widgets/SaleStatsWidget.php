<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Sale;

class SaleStatsWidget extends BaseStatsOverviewWidget
{
    use FormatsCurrency;

    protected static ?int $sort = 1;

    protected int|array|null $columns = 3;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Sales Dashboard Stats Widget');
    }

    protected function getStore()
    {
        return Filament::getTenant();
    }

    protected function getStats(): array
    {
        $store = $this->getStore();
        $startDate = now()->startOfYear();
        $endDate = now()->endOfYear();

        // Base query for completed sales in current year
        $baseQuery = Sale::query()
            ->where('status', SaleStatus::Completed->value)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        // Revenue stat - current year only (only Paid and Credit sales)
        $revenueRaw = (clone $baseQuery)
            ->whereIn('payment_status', [
                SalePaymentStatus::Paid->value,
                SalePaymentStatus::Credit->value,
            ])
            ->sum('total');
        $revenue = $this->convertFromStorage($revenueRaw, $store);

        // Profit stat - current year only (all completed sales, optimized with database query)
        // Revenue: sum of all completed sale totals (for profit calculation, include all payment statuses)
        $allRevenueRaw = $baseQuery->sum('total');

        // Cost: sum of all supplier_totals from sale_variation pivot table for completed sales
        $costRaw = DB::table('sale_variation')
            ->join('sales', 'sale_variation.sale_id', '=', 'sales.id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->sum('sale_variation.supplier_total');

        // Profit = Revenue - Cost (both in storage format, convert after calculation)
        $profitRaw = $allRevenueRaw - ($costRaw ?? 0);
        $profit = $this->convertFromStorage($profitRaw, $store);

        // Number of Sales stat - current year only
        $salesCount = (clone $baseQuery)->count();

        return [
            BaseStatsOverviewWidget\Stat::make('Revenue', $this->formatCompactCurrency($revenue, $store))
                ->description('Current year revenue')
                ->color('success'),
            BaseStatsOverviewWidget\Stat::make('Profit', $this->formatCompactCurrency($profit, $store, true))
                ->description('Current year profit')
                ->color('primary'),
            BaseStatsOverviewWidget\Stat::make('Number of Sales', number_format($salesCount))
                ->description('Current year sales count')
                ->color('info'),
        ];
    }
}
