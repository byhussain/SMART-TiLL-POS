<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;

uses(RefreshDatabase::class);

it('allows updating store region information', function () {
    $country = Country::query()->create([
        'name' => 'Pakistan',
        'code' => 'PK',
    ]);

    $currency = Currency::query()->create([
        'name' => 'Pakistani Rupee',
        'code' => 'PKR',
        'decimal_places' => 2,
    ]);

    $timezone = Timezone::query()->create([
        'name' => 'Asia/Karachi',
        'offset' => '+05:00',
    ]);

    $country->currencies()->attach($currency->id);
    $country->timezones()->attach($timezone->id);

    $store = Store::query()->create([
        'name' => 'Old Name',
    ]);

    $store->update([
        'name' => 'New Name',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone_id' => $timezone->id,
    ]);

    expect($store->fresh()->name)->toBe('New Name');
    expect($store->fresh()->country_id)->toBe($country->id);
    expect($store->fresh()->currency_id)->toBe($currency->id);
    expect($store->fresh()->timezone_id)->toBe($timezone->id);
});
