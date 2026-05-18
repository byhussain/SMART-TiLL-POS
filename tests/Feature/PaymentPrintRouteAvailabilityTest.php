<?php

it('registers print payment route with core controller or local fallback', function (): void {
    $contents = file_get_contents(base_path('routes/web.php'));

    // The inline closure fallback was extracted to PrintPaymentReceiptController
    // so the route table can be cached (route:cache fails on closures). Both
    // branches still wire up the same named route.
    expect($contents)
        ->toContain("if (! Route::has('print.payment')) {")
        ->toContain('class_exists(PublicPaymentReceiptController::class)')
        ->toContain('PublicPaymentReceiptController::class')
        ->toContain('PrintPaymentReceiptController::class')
        ->toContain("Route::get('/payments/{payment}/receipt', \$printPaymentController)")
        ->toContain("->name('print.payment');");
});

it('has a fallback controller for the print payment route', function (): void {
    $controllerPath = app_path('Http/Controllers/PrintPaymentReceiptController.php');

    expect(file_exists($controllerPath))->toBeTrue();

    $contents = file_get_contents($controllerPath);

    expect($contents)
        ->toContain('class PrintPaymentReceiptController')
        ->toContain('public function __invoke(Request $request, Payment $payment)')
        ->toContain("return response()->view('print.payment'");
});

it('has a payment print blade in pos for fallback rendering', function (): void {
    $viewPath = resource_path('views/print/payment.blade.php');

    expect(file_exists($viewPath))->toBeTrue();

    $contents = file_get_contents($viewPath);

    expect($contents)
        ->toContain('Payment Receipt')
        ->toContain('prepareAndPrint');
});
