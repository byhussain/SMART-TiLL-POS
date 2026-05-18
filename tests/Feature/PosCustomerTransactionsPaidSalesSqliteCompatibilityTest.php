<?php

it('keeps paid-sale customer transaction unions compatible with sqlite transaction sync columns', function (): void {
    $contents = file_get_contents(base_path('vendor/smart-till/core/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php'));

    // Validates the SQLite-safe query shape: explicit column listing via
    // helper methods so the cloud sync's extra columns (sync_state etc.)
    // can be transparently spread across the UNION without breaking the
    // pivot table. The helper method names have evolved; this asserts the
    // current contract.
    expect($contents)
        ->toContain('$columns = $this->transactionTableColumns();')
        ->toContain('$customer = $this->getOwnerRecord();')
        ->toContain('...$this->qualifyTransactionColumns($columns)')
        ->toContain("->where('transactions.transactionable_id', \$customer->getKey())")
        ->toContain("->whereIn('transactions.transactionable_type', Customer::transactionMorphTypes())")
        ->toContain("return Schema::getColumnListing('transactions');");
});
