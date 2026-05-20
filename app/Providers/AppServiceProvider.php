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
        // Layer 3 of the SQLite/database-queue guard (see
        // forceBackgroundQueueOnSqlite). Fires whenever something resolves
        // the QueueManager — e.g., the WorkCommand reading `manager->
        // connection($connectionName)` after the booted callbacks have
        // settled. setDefaultDriver flips the manager's view of the
        // default even if config('queue.default') stays at 'database'.
        $this->app->resolving('queue', function ($queue): void {
            if (! $this->defaultDatabaseDriverIsSqlite()) {
                return;
            }
            if (method_exists($queue, 'setDefaultDriver')) {
                $queue->setDefaultDriver('background');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // NativePHP's NativeServiceProvider::configureApp() unconditionally
        // forces `queue.default = 'database'` and points it at its own
        // `nativephp` SQLite connection during its `boot()`. Our config-file
        // setting and our register()-time override BOTH run before that and
        // get overwritten.
        //
        // The Application::booted() callback fires AFTER every service
        // provider has booted, so this runs last and wins — regardless of
        // provider order or NativePHP version.
        $this->app->booted(function (): void {
            $this->forceBackgroundQueueOnSqlite();
        });

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

            $this->applySqliteHardeningPragmas($connection);
        });

        // Also apply to the already-resolved default connection on boot, in
        // case it was opened before this listener registered (e.g. via early
        // service-provider DB access).
        try {
            $defaultConnection = $this->app->make('db')->connection();
            if ($defaultConnection instanceof SQLiteConnection) {
                $this->applySqliteHardeningPragmas($defaultConnection);
            }
        } catch (\Throwable) {
            // No DB available yet (e.g. running `artisan key:generate` with
            // no DB file). Safe to ignore — the listener above will pick up
            // the first real connection.
        }
    }

    /**
     * Belt-and-suspenders guard against the queue worker trying to use the
     * `database` driver against SQLite. The database driver opens its own
     * transaction in `DatabaseQueue::pop()` to atomically reserve a job —
     * which races with our own DB::transaction usage (snapshot import,
     * upsertPulledRows, etc.) and throws "cannot start a transaction
     * within a transaction" mid-poll, killing the daemon.
     *
     * Three layers, because NativePHP unconditionally re-applies
     * `queue.default = database` from its own configureApp() during
     * packageBooted, AND the worker is spawned as `queue:work` with no
     * --connection flag so it falls back to that config:
     *
     *   1. Flip `queue.default` from `database` to `background` so any
     *      caller falling back to the default gets the safe driver.
     *
     *   2. Swap the `database` connection's DRIVER itself to `background`.
     *      This wins even when `queue.default` somehow ends up as
     *      `database` again later — QueueManager::resolve('database')
     *      reads the driver from the connection config and dispatches via
     *      the background connector instead of DatabaseQueue.
     *
     *   3. Register an app->resolving hook on the `queue` singleton so
     *      anything that resolves the QueueManager (e.g., direct facade
     *      use) also has the default driver flipped at that moment.
     *
     * Sqlite check is on `database.connections.<default>.driver` rather
     * than the connection NAME so we catch the NativePHP-installed
     * `nativephp` connection too (which has driver=sqlite but a non-
     * `sqlite` name).
     */
    private function forceBackgroundQueueOnSqlite(): void
    {
        if (! $this->defaultDatabaseDriverIsSqlite()) {
            return;
        }

        // Layer 1: default driver.
        if ((string) config('queue.default') === 'database') {
            config(['queue.default' => 'background']);
        }

        // Layer 2: swap the `database` queue connection itself to use
        // the background driver. Survives any later reset of
        // queue.default = database because the resolver reads the
        // connection config's `driver` key.
        if ((string) config('queue.connections.database.driver') === 'database') {
            config(['queue.connections.database.driver' => 'background']);
        }
    }

    /**
     * True when whichever database connection is currently the default
     * resolves to the sqlite driver — covers the bundled `sqlite`
     * connection AND NativePHP's `nativephp` connection (both use
     * driver=sqlite under different connection names).
     */
    private function defaultDatabaseDriverIsSqlite(): bool
    {
        $default = (string) config('database.default', 'sqlite');
        $driver = (string) config("database.connections.{$default}.driver", 'sqlite');

        return $driver === 'sqlite';
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
