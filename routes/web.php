<?php

use App\Http\Controllers\StartupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SmartTill\Core\Http\Controllers\PublicPaymentReceiptController;
use SmartTill\Core\Models\Payment;

Route::get('/', function () {
    return to_route('startup.index');
});

Route::get('/startup', [StartupController::class, 'index'])->name('startup.index');
Route::post('/startup/guest', [StartupController::class, 'continueAsGuest'])->name('startup.guest');
Route::get('/startup/guest/setup', [StartupController::class, 'guestSetupForm'])->name('startup.guest.setup');
Route::post('/startup/guest/setup', [StartupController::class, 'guestSetupStore'])->name('startup.guest.setup.store');
Route::get('/startup/cloud', [StartupController::class, 'cloudForm'])->name('startup.cloud.form');
Route::post('/startup/cloud/login', [StartupController::class, 'cloudLogin'])->name('startup.cloud.login');
Route::post('/startup/cloud/register', [StartupController::class, 'cloudRegister'])->name('startup.cloud.register');
Route::get('/startup/cloud/stores', [StartupController::class, 'cloudStores'])->name('startup.cloud.stores');
Route::post('/startup/cloud/select-store', [StartupController::class, 'selectCloudStore'])->name('startup.cloud.select-store');
Route::get('/startup/cloud/bootstrap/{store}', [StartupController::class, 'cloudBootstrap'])
    ->whereNumber('store')
    ->name('startup.cloud.bootstrap');
Route::post('/startup/cloud/disconnect', [StartupController::class, 'disconnectCloud'])->name('startup.cloud.disconnect');
Route::post('/startup/cloud/sync-now', [StartupController::class, 'syncNow'])->name('startup.cloud.sync-now');
Route::post('/startup/cloud/sync-module', [StartupController::class, 'syncModule'])->name('startup.cloud.sync-module');
Route::get('/startup/cloud/sync-status', [StartupController::class, 'syncStatus'])->name('startup.cloud.sync-status');
Route::get('/startup/cloud/sync-log', [StartupController::class, 'syncLog'])->name('startup.cloud.sync-log');

if (! Route::has('print.payment')) {
    if (class_exists(PublicPaymentReceiptController::class)) {
        Route::get('/payments/{payment}/receipt', PublicPaymentReceiptController::class)
            ->middleware('web')
            ->name('print.payment');
    } else {
        Route::get('/payments/{payment}/receipt', function (Request $request, Payment $payment) {
            $next = urldecode((string) $request->query('next', '/'));

            return response()->view('print.payment', [
                'payment' => $payment->loadMissing([
                    'payable',
                    'store.currency',
                    'store.timezone',
                ]),
                'next' => $next,
                'paper' => $request->query('paper'),
            ]);
        })->middleware('web')->name('print.payment');
    }
}
