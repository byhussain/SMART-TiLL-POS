<?php

namespace App\Providers;

use App\Observers\DispatchCloudSyncObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use SmartTill\Core\Models\Attribute;
use SmartTill\Core\Models\Brand;
use SmartTill\Core\Models\Category;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Payment;
use SmartTill\Core\Models\Product;
use SmartTill\Core\Models\ProductAttribute;
use SmartTill\Core\Models\PurchaseOrder;
use SmartTill\Core\Models\PurchaseOrderProduct;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\SalePreparableItem;
use SmartTill\Core\Models\SaleVariation;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\StoreSetting;
use SmartTill\Core\Models\Supplier;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\UnitDimension;
use SmartTill\Core\Models\Variation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $observer = app(DispatchCloudSyncObserver::class);

        foreach ($this->syncObservedModels() as $modelClass) {
            if (class_exists($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $modelClass::observe($observer);
            }
        }

    }

    /**
     * @return array<int, class-string<Model>>
     */
    private function syncObservedModels(): array
    {
        return [
            StoreSetting::class,
            Brand::class,
            Category::class,
            Attribute::class,
            Unit::class,
            UnitDimension::class,
            Product::class,
            ProductAttribute::class,
            Variation::class,
            Stock::class,
            Customer::class,
            Supplier::class,
            PurchaseOrder::class,
            PurchaseOrderProduct::class,
            Sale::class,
            SaleVariation::class,
            SalePreparableItem::class,
            Payment::class,
            Transaction::class,
        ];
    }
}
