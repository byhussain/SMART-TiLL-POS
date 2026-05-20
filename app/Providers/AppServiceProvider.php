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
                $queue->setDefaultDriver('sync');
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
     * Force the queue to the `sync` driver whenever SQLite is the active
     * database, overriding NativePHP's runtime `queue.default = database`
     * setting.
     *
     * Why `sync` and not `background` or `database`:
     *
     *   - `database`: DatabaseQueue::pop() opens its own transaction to
     *     atomically reserve a job. On SQLite that races with our own
     *     DB::transaction usage (snapshot import, upsertPulledRows, etc.)
     *     and throws "cannot start a transaction within a transaction"
     *     mid-poll, killing the daemon.
     *
     *   - `background`: defers each job via Concurrency::process which
     *     relies on PHP request termination + subprocess spawn. Inside
     *     NativePHP's bundled runtime that mechanism doesn't reliably
     *     fire (`php` binary path resolution issues, request lifecycle
     *     differences), so jobs are silently never executed. Observed
     *     in production: bootstrap dispatched, never runs, UI stuck at 0%.
     *
     *   - `sync`: jobs run inline at dispatch time, in-process. No
     *     daemon, no subprocess, no defer, no race. The snapshot
     *     bootstrap blocks the cloud-connect HTTP response for its
     *     duration (typically seconds with the snapshot path) — that's
     *     fine; the user is waiting on the connect spinner anyway, and
     *     the bootstrap UI immediately sees `isStoreBootstrapped=true`
     *     when it loads.
     *
     * Three layers because NativePHP's `NativeServiceProvider::
     * configureApp()` re-applies `queue.default = database` during its
     * `boot()`, AND the spawned `queue:work` worker has no
     * `--connection` flag so it falls back to whatever the resolved
     * default is.
     */
    private function forceBackgroundQueueOnSqlite(): void
    {
        if (! $this->defaultDatabaseDriverIsSqlite()) {
            return;
        }

        // Layer 1: flip the default driver name to `sync`.
        if (in_array((string) config('queue.default'), ['database', 'background'], true)) {
            config(['queue.default' => 'sync']);
        }

        // Layer 2: swap the `database` queue connection's driver itself
        // to `sync`. Wins even when something later resets queue.default
        // back to `database` because the resolver reads the connection
        // config's `driver` key.
        if (in_array((string) config('queue.connections.database.driver'), ['database', 'background'], true)) {
            config(['queue.connections.database.driver' => 'sync']);
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
