<?php

it('registers print payment route with core controller or local fallback', function (): void {
    $contents = file_get_contents(base_path('routes/web.php'));

    expect($contents)
        ->toContain("if (! Route::has('print.payment')) {")
        ->toContain('class_exists(PublicPaymentReceiptController::class)')
        ->toContain("Route::get('/payments/{payment}/receipt', PublicPaymentReceiptController::class)")
        ->toContain("Route::get('/payments/{payment}/receipt', function (Request \$request, Payment \$payment)")
        ->toContain("->name('print.payment');");
});

it('has a payment print blade in pos for fallback rendering', function (): void {
    $viewPath = resource_path('views/print/payment.blade.php');

    expect(file_exists($viewPath))->toBeTrue();

    $contents = file_get_contents($viewPath);

    expect($contents)
        ->toContain('Payment Receipt')
        ->toContain('prepareAndPrint');
});
