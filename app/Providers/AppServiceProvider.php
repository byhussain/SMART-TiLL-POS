<?php

namespace App\Providers;

use App\Observers\DispatchCloudSyncObserver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SmartTill\Core\Models\Attribute;
use SmartTill\Core\Models\Brand;
use SmartTill\Core\Models\Category;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Image;
use SmartTill\Core\Models\ModelActivity;
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
        $this->hardenSqliteConnections();

        $observer = app(DispatchCloudSyncObserver::class);

        foreach ($this->syncObservedModels() as $modelClass) {
            if (class_exists($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $modelClass::observe($observer);
            }
        }

    }

    /**
     * Re-apply concurrency-critical PRAGMAs on every freshly-established
     * SQLite connection. Laravel already sets `journal_mode`, `busy_timeout`,
     * and `synchronous` from config/database.php — but this is belt-and-
     * suspenders for cases where a connection is created outside the normal
     * config path (NativePHP runtime DB reset, queue worker cold start on
     * Windows, ad-hoc PDO from a sub-process). Without this hook those
     * connections fall back to SQLite's defaults — `journal_mode=DELETE`
     * and `busy_timeout=0` — which produces "database is locked" errors the
     * moment two processes try to write concurrently.
     */
    private function hardenSqliteConnections(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if (! $connection instanceof SQLiteConnection) {
                return;
            }

            $this->rescueStalePdoTransaction($connection);
            $this->applySqliteHardeningPragmas($connection);
        });

        // Also apply to the already-resolved default connection on boot, in
        // case it was opened before this listener registered (e.g. via early
        // service-provider DB access).
        try {
            $defaultConnection = $this->app->make('db')->connection();
            if ($defaultConnection instanceof SQLiteConnection) {
                $this->rescueStalePdoTransaction($defaultConnection);
                $this->applySqliteHardeningPragmas($defaultConnection);
            }
        } catch (\Throwable) {
            // No DB available yet (e.g. running `artisan key:generate` with
            // no DB file). Safe to ignore — the listener above will pick up
            // the first real connection.
        }
    }

    /**
     * Defensively rollback any open PDO transaction on a freshly-resolved
     * SQLite connection.
     *
     * This guards against the "cannot start a transaction within a
     * transaction" error in the queue worker daemon: if a previous code
     * path (raw SQL with BEGIN, an exception that bypassed Laravel's
     * transaction wrapper rollback, etc.) left PDO with an open
     * transaction, the next DatabaseQueue::pop() — which calls
     * `beginTransaction()` — throws because PDO says "I already have
     * one". Forcing the depth back to zero on connection establish means
     * the worker can always recover on its next iteration even if a
     * previous job poisoned the state.
     */
    private function rescueStalePdoTransaction(Connection $connection): void
    {
        try {
            $pdo = $connection->getPdo();
            while ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable) {
            // PDO not yet usable, or rollBack against a clean state. Both safe to ignore.
        }
    }

    private function applySqliteHardeningPragmas(Connection $connection): void
    {
        try {
            // Order matters: WAL must be set BEFORE busy_timeout so that
            // SQLite's lock manager honours the timeout in WAL mode.
            $connection->statement('PRAGMA journal_mode = WAL');
            $connection->statement('PRAGMA busy_timeout = 60000');
            $connection->statement('PRAGMA synchronous = NORMAL');
            // Auto-checkpoint after ~1000 pages of WAL so the WAL file
            // doesn't grow unbounded and stall checkpoints under load.
            $connection->statement('PRAGMA wal_autocheckpoint = 1000');
        } catch (\Throwable) {
            // PRAGMA failure (e.g. read-only file) is non-fatal — Laravel's
            // config-based PRAGMAs will have already tried, and an actual
            // bad DB will surface a clearer error on the first real query.
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
            // Image / ModelActivity were pulled but not pushed, so local
            // edits silently lost on every cloud sync. Registering them here
            // hooks them into the same observer pipeline as everything else.
            Image::class,
            ModelActivity::class,
        ];
    }
}
