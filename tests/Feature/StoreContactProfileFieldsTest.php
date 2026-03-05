<?php

use App\Models\Store;

it('has store contact columns and fillable attributes for receipts', function () {
    $migrationPath = database_path('migrations/2026_02_27_000003_add_contact_columns_to_stores_table.php');
    $migrationContents = file_get_contents($migrationPath);

    expect(file_exists($migrationPath))->toBeTrue()
        ->and($migrationContents)->toContain("'email'")
        ->and($migrationContents)->toContain("'phone'")
        ->and($migrationContents)->toContain("'address'");

    $fillable = (new Store)->getFillable();

    expect($fillable)
        ->toContain('email')
        ->toContain('phone')
        ->toContain('address');

    $profilePageContents = file_get_contents(app_path('Filament/Pages/Tenancy/EditStoreProfile.php'));

    expect($profilePageContents)
        ->toContain("TextInput::make('email')")
        ->toContain("TextInput::make('phone')")
        ->toContain("TextInput::make('address')")
        ->toContain('->columns(2)')
        ->toContain('->columnSpanFull()');
});
