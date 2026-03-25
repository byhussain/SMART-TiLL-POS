<?php

it('keeps customer payment ledger writes compatible with imported customer transaction morph types', function (): void {
    $contents = file_get_contents(base_path('vendor/smart-till/core/src/Services/PaymentService.php'));

    expect($contents)
        ->toContain('use SmartTill\Core\Models\Transaction;')
        ->toContain('$lastBalance = $this->getLatestCustomerBalance($payable);')
        ->toContain("->whereIn('transactionable_type', Customer::transactionMorphTypes())")
        ->toContain('Transaction::query()->create([')
        ->toContain("'transactionable_type' => \$payable->getMorphClass(),")
        ->toContain("'transactionable_id' => \$payable->getKey(),");
});
