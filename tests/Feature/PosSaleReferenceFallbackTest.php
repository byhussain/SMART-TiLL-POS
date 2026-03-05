<?php

it('assigns store-scoped sale reference in pos app service provider before create', function (): void {
    $contents = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    expect($contents)
        ->toContain('Sale::creating(function (Sale $sale): void {')
        ->toContain("if (trim((string) (\$sale->reference ?? '')) !== '') {")
        ->toContain("->where('store_id', \$storeId)")
        ->toContain("->whereNotNull('reference')")
        ->toContain('$sale->reference = (string) $nextReference;');
});
