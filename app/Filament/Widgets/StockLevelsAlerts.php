<?php

namespace App\Filament\Widgets;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Variation;

class StockLevelsAlerts extends TableWidget
{
    protected static ?int $sort = 9;

    protected static string|BackedEnum|null $headingIcon = Heroicon::ExclamationTriangle;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Stock Levels Alerts Widget');
    }

    public function table(Table $table): Table
    {
        $storeId = Filament::getTenant()->id;

        return $table
            ->query(fn (): Builder => Variation::query()
                ->whereHas('product', fn ($query) => $query->where('store_id', $storeId))
                ->withBarcodeStock()
                ->whereRaw('(select coalesce(sum(stocks.stock), 0) from stocks where stocks.variation_id = variations.id) < 10')
            )
            ->columns([
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric()
                    ->icon(fn ($record) => match (true) {
                        $record->stock <= 3 => Heroicon::ExclamationCircle,
                        $record->stock <= 5 => Heroicon::ExclamationTriangle,
                        default => Heroicon::CheckCircle,
                    })
                    ->iconColor(fn ($record) => match (true) {
                        $record->stock <= 3 => 'danger',
                        $record->stock <= 5 => 'warning',
                        default => 'success',
                    })
                    ->color(fn ($record) => match (true) {
                        $record->stock <= 3 => 'danger',
                        $record->stock <= 5 => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('stock')
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5]);
    }
}
