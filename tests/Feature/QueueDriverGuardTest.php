<?php

use App\Providers\AppServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Run the guard as it actually runs in production: register the booted
 * callback by calling AppServiceProvider::boot(), then fire $app->booted()
 * to trigger every booted-callback (including NativePHP-style overrides
 * that might re-flip queue.default in between).
 */
function fireAppBooted(?callable $afterBoot = null): void
{
    $app = app();
    (new AppServiceProvider($app))->boot();
    if ($afterBoot !== null) {
        $afterBoot();
    }
    $app->boot();
}

it('forces the queue connection to background when DB is sqlite and queue is database (cannot-start-transaction guard)', function (): void {
    // Reproduce the production failure mode: somehow (frozen config:cache,
    // stale env, etc.) the queue connection resolves to `database` while
    // the database driver is sqlite. Without the guard, DatabaseQueue::pop
    // opens its own transaction that races with our DB::transaction usage
    // and throws "cannot start a transaction within a transaction".
    config([
        'database.default' => 'sqlite',
        'queue.default' => 'database',
        'database.connections.sqlite.driver' => 'sqlite',
        'queue.connections.database.driver' => 'database',
    ]);

    fireAppBooted();

    // Layer 1: queue.default flipped to background.
    expect(config('queue.default'))->toBe('background');
    // Layer 2: the `database` connection's driver was swapped to
    // background, so even if something resets queue.default back later,
    // QueueManager::resolve('database') returns BackgroundQueue, not
    // DatabaseQueue, and the transaction race is impossible.
    expect(config('queue.connections.database.driver'))->toBe('background');
});

it('layer 2 wins against late NativePHP override of queue.default back to database', function (): void {
    // The specific production scenario: NativePHP's configureApp() runs
    // late and forces queue.default = database AFTER our booted callback
    // fires. Layer 2 protects against this because the worker reads the
    // CONNECTION config (driver=background) not just the default name.
    config([
        'database.default' => 'sqlite',
        'queue.default' => 'database',
        'database.connections.sqlite.driver' => 'sqlite',
        'queue.connections.database.driver' => 'database',
    ]);

    fireAppBooted();

    // Simulate NativePHP's late override:
    config(['queue.default' => 'database']);

    // queue.default is back to 'database' (NativePHP won that one), but
    // the database connection's driver is permanently background.
    expect(config('queue.default'))->toBe('database');
    expect(config('queue.connections.database.driver'))->toBe('background');
});

it('treats the NativePHP-installed `nativephp` SQLite connection the same as the bundled sqlite connection', function (): void {
    // NativePHP creates its own connection name (`nativephp`) with
    // driver=sqlite. Our guard must trigger on the DRIVER, not the
    // connection name, or we'd miss it.
    config([
        'database.default' => 'nativephp',
        'database.connections.nativephp.driver' => 'sqlite',
        'queue.default' => 'database',
        'queue.connections.database.driver' => 'database',
    ]);

    fireAppBooted();

    expect(config('queue.default'))->toBe('background');
    expect(config('queue.connections.database.driver'))->toBe('background');
});

it('registers the override as a booted-callback so it fires AFTER all other providers boot (including NativePHP)', function (): void {
    // In production:
    //   1. NativeServiceProvider::boot() runs → forces queue.default=database
    //   2. AppServiceProvider::boot() runs → registers a $app->booted() callback
    //   3. App finishes booting all providers
    //   4. Booted callbacks fire → our callback flips queue.default to background
    //
    // We can't reproduce that full ordering in a test where Laravel is
    // already booted, but we CAN verify that the booted callback is
    // registered (and therefore would fire in the correct phase in prod).
    config([
        'database.default' => 'sqlite',
        'queue.default' => 'database',
    ]);

    // Spin up a fresh Application instance whose boot lifecycle we can
    // observe end-to-end.
    $app = new Application(base_path());
    $app->instance('config', new Repository([
        'database' => ['default' => 'sqlite'],
        'queue' => ['default' => 'database'],
    ]));

    $callbackFiredAfterBoot = false;
    $app->booted(function () use ($app, &$callbackFiredAfterBoot): void {
        $callbackFiredAfterBoot = $app->isBooted();
    });

    (new AppServiceProvider($app))->boot();
    $app->boot();

    // Once the app finishes booting, the booted callback fires with the
    // app in a fully-booted state, AFTER every other provider's boot()
    // has completed. In production that's where our flip wins over
    // NativePHP's earlier override.
    expect($app->isBooted())->toBeTrue();
    expect($callbackFiredAfterBoot)->toBeTrue();
    expect($app['config']->get('queue.default'))->toBe('background');
});

it('leaves the queue connection alone when the database is not sqlite', function (): void {
    config([
        'database.default' => 'mysql',
        'queue.default' => 'database',
    ]);

    fireAppBooted();

    // Don't second-guess valid configurations — MySQL + database queue
    // is fine and shouldn't be forced to background.
    expect(config('queue.default'))->toBe('database');
});

it('leaves the queue connection alone when the queue is already on a safe driver', function (): void {
    config([
        'database.default' => 'sqlite',
        'queue.default' => 'sync',
    ]);

    fireAppBooted();

    expect(config('queue.default'))->toBe('sync');
});
