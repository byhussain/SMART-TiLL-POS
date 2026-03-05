<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to store id when slug attribute is missing', function (): void {
    $store = Store::query()->create(['name' => 'Fallback Store']);

    expect($store->slug)->toBe((string) $store->id);
});
