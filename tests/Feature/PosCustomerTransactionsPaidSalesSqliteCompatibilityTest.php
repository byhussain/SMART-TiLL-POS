<?php

it('keeps paid-sale customer transaction unions compatible with sqlite transaction sync columns', function (): void {
    $contents = file_get_contents(base_path('vendor/smart-till/core/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php'));

    expect($contents)
        ->toContain('$columns = $this->transactionTableColumns();')
        ->toContain('$customer = $this->getOwnerRecord();')
        ->toContain('->select($this->qualifyTransactionColumns($columns))')
        ->toContain("->where('transactions.transactionable_id', \$customer->getKey())")
        ->toContain("->whereIn('transactions.transactionable_type', Customer::transactionMorphTypes())")
        ->toContain("return Schema::getColumnListing('transactions');")
        ->toContain('default => $query->selectRaw("null as {$column}"),');
});
