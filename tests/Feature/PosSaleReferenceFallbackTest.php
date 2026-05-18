<?php

it('assigns store-scoped reference for sales via the core HasStoreScopedReference trait', function (): void {
    // The inline Sale::creating hook in this app's AppServiceProvider was
    // replaced by the core package's HasStoreScopedReference trait +
    // StoreScopedReferenceObserver, which handles every model that has a
    // store-scoped sequential reference (sales, purchase orders, etc.).
    // This test now verifies the trait is what's mounted on the Sale model.
    $saleContents = file_get_contents(base_path('vendor/smart-till/core/src/Models/Sale.php'));
    $traitContents = file_get_contents(base_path('vendor/smart-till/core/src/Traits/HasStoreScopedReference.php'));

    expect($saleContents)
        ->toContain('use SmartTill\\Core\\Traits\\HasStoreScopedReference')
        ->toContain('HasStoreScopedReference');

    expect($traitContents)
        ->toContain("->where('store_id', \$storeId)")
        ->toContain("->whereNotNull('reference')")
        ->toContain("update(['reference' => (string) \$nextReference])");
});
