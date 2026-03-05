<?php

it('configures POS sidebar to include settings group and core pages discovery', function () {
    $providerFile = base_path('app/Providers/Filament/StorePanelProvider.php');
    $contents = file_get_contents($providerFile);

    expect($contents)
        ->toContain("'Settings'")
        ->toContain('vendor/smart-till/core/src/Filament/Pages')
        ->toContain("'Sales & Transactions'");
});

it('uses POS-local receipt template without print layout controls', function () {
    $receiptView = resource_path('views/print/sale.blade.php');

    expect(file_exists($receiptView))->toBeTrue();

    $contents = file_get_contents($receiptView);

    expect($contents)
        ->not->toContain('Print Controls (Hidden when printing)')
        ->not->toContain('$paperOptions = \\SmartTill\\Core\\Enums\\PrintOption::cases()');
});
