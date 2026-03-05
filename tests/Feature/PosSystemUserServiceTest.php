<?php

use App\Models\Store;
use App\Services\PosSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('attaches system user to all local stores on authentication', function (): void {
    $storeA = Store::query()->create(['name' => 'Store A']);
    $storeB = Store::query()->create(['name' => 'Store B']);

    $service = app(PosSystemUserService::class);
    $systemUser = $service->ensureAuthenticated();

    expect($systemUser->stores()->whereIn('stores.id', [$storeA->id, $storeB->id])->count())->toBe(2);
});
