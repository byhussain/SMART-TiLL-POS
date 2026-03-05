<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;

uses(RefreshDatabase::class);

it('stores table has region columns and store resolves region relations', function () {
    expect(Schema::hasColumn('stores', 'country_id'))->toBeTrue();
    expect(Schema::hasColumn('stores', 'currency_id'))->toBeTrue();
    expect(Schema::hasColumn('stores', 'timezone_id'))->toBeTrue();

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
        'name' => 'Demo Store',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone_id' => $timezone->id,
    ]);

    expect($store->country?->id)->toBe($country->id);
    expect($store->currency?->id)->toBe($currency->id);
    expect($store->timezone?->id)->toBe($timezone->id);
});
