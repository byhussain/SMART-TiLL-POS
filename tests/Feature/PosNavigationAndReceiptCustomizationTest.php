<?php

it('configures POS sidebar to include settings group and core pages discovery', function () {
    $providerFile = base_path('app/Providers/Filament/StorePanelProvider.php');
    $contents = file_get_contents($providerFile);

    expect($contents)
        ->toContain("'Settings'")
        ->toContain('vendor/smart-till/core/src/Filament/Pages')
        ->toContain("'Sales & Transactions'");
});

it('uses POS-local receipt template with a print-safe back toolbar', function () {
    $receiptView = resource_path('views/print/sale.blade.php');

    expect(file_exists($receiptView))->toBeTrue();

    $contents = file_get_contents($receiptView);

    expect($contents)
        ->not->toContain('Print Controls (Hidden when printing)')
        ->not->toContain('$paperOptions = \\SmartTill\\Core\\Enums\\PrintOption::cases()')
        ->toContain('class="no-print flex items-center justify-center gap-3 mb-4 bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex-wrap"')
        ->toContain('>Print</button>')
        ->toContain('>Back</a>')
        ->toContain('function goBack(event)')
        ->toContain("if (event.key === 'Escape')");
});

it('prefers local sale id over server id when printing the POS invoice number', function () {
    $receiptView = resource_path('views/print/sale.blade.php');

    expect(file_exists($receiptView))->toBeTrue();

    $contents = file_get_contents($receiptView);

    expect($contents)
        ->toContain('$invoiceNumber = (string) ($sale->local_id ?: ($sale->server_id ?: $sale->id));')
        ->toContain('<title>Invoice #{{ $sale->local_id ?: ($sale->server_id ?: $sale->id) }}</title>')
        ->toContain('#{{ $invoiceNumber }}');
});
