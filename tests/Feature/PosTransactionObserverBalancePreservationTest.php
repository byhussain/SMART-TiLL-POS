<?php

it('keeps explicit transaction balances intact in the installed core observer', function (): void {
    $contents = file_get_contents(base_path('vendor/smart-till/core/src/Observers/TransactionObserver.php'));

    expect($contents)
        ->toContain('if ($transaction->amount_balance === null) {')
        ->toContain('if ($transaction->quantity_balance === null) {');
});
