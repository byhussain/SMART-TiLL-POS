<?php

use App\Filament\Widgets\CustomerStatsWidget;
use App\Filament\Widgets\SaleStatsWidget;
use App\Filament\Widgets\StockLevelsAlerts;
use App\Filament\Widgets\SupplierStatsWidget;
use Filament\Facades\Filament;
use Filament\Widgets\FilamentInfoWidget;

it('registers dashboard widgets used on server panel', function (): void {
    $widgets = Filament::getPanel('store')->getWidgets();
    $expectedCustomerWidget = class_exists(\SmartTill\Core\Filament\Widgets\CustomerStatsWidget::class)
        ? \SmartTill\Core\Filament\Widgets\CustomerStatsWidget::class
        : CustomerStatsWidget::class;
    $expectedSupplierWidget = class_exists(\SmartTill\Core\Filament\Widgets\SupplierStatsWidget::class)
        ? \SmartTill\Core\Filament\Widgets\SupplierStatsWidget::class
        : SupplierStatsWidget::class;

    expect($widgets)->toContain(SaleStatsWidget::class);
    expect($widgets)->toContain($expectedCustomerWidget);
    expect($widgets)->toContain($expectedSupplierWidget);
    expect($widgets)->toContain(StockLevelsAlerts::class);
    expect($widgets)->not->toContain(FilamentInfoWidget::class);
});
