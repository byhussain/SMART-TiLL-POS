<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;

class EditStoreProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Store settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('General')
                ->description('Update the basic information for this store.')
                ->schema([
                    TextInput::make('name')
                        ->label('Store name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label('Phone')
                        ->maxLength(50),
                    TextInput::make('address')
                        ->label('Address')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Region')
                ->description('Update country, currency, and timezone.')
                ->schema([
                    Select::make('country_id')
                        ->label('Country')
                        ->options(fn () => Country::query()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (! $state) {
                                $set('currency_id', null);
                                $set('timezone_id', null);

                                return;
                            }

                            $country = Country::query()->with(['currencies', 'timezones'])->find($state);
                            if (! $country) {
                                $set('currency_id', null);
                                $set('timezone_id', null);

                                return;
                            }

                            $set('currency_id', $country->currencies->first()?->id);
                            $set('timezone_id', $country->timezones->first()?->id);
                        }),
                    Select::make('currency_id')
                        ->label('Currency')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->dehydrated()
                        ->helperText(function (callable $get): ?string {
                            $currencyId = $get('currency_id');

                            if (! $currencyId) {
                                return null;
                            }

                            $currency = Currency::query()->find($currencyId);

                            if (! $currency) {
                                return null;
                            }

                            $decimalPlaces = (int) $currency->decimal_places;
                            $example = number_format(1234.567, $decimalPlaces);

                            return "Example format: {$example}";
                        })
                        ->options(function (callable $get): array {
                            $countryId = $get('country_id');
                            if (! $countryId) {
                                return [];
                            }

                            $country = Country::query()->with('currencies')->find($countryId);

                            return $country?->currencies
                                ->mapWithKeys(fn ($currency) => [$currency->id => "{$currency->name} ({$currency->code})"])
                                ->toArray() ?? [];
                        })
                        ->disabled(function (callable $get): bool {
                            $countryId = $get('country_id');
                            if (! $countryId) {
                                return true;
                            }

                            $country = Country::query()->withCount('currencies')->find($countryId);

                            return ($country?->currencies_count ?? 0) <= 1;
                        }),
                    Select::make('timezone_id')
                        ->label('Timezone')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->dehydrated()
                        ->helperText(function (callable $get): ?string {
                            $timezoneId = $get('timezone_id');

                            if (! $timezoneId) {
                                return null;
                            }

                            $timezone = Timezone::query()->find($timezoneId);

                            if (! $timezone) {
                                return null;
                            }

                            $offset = $timezone->offset ?: 'N/A';

                            return "Example offset: {$offset}";
                        })
                        ->options(function (callable $get): array {
                            $countryId = $get('country_id');
                            if (! $countryId) {
                                return [];
                            }

                            $country = Country::query()->with('timezones')->find($countryId);

                            return $country?->timezones
                                ->mapWithKeys(fn ($timezone) => [$timezone->id => $timezone->name])
                                ->toArray() ?? [];
                        })
                        ->disabled(function (callable $get): bool {
                            $countryId = $get('country_id');
                            if (! $countryId) {
                                return true;
                            }

                            $country = Country::query()->withCount('timezones')->find($countryId);

                            return ($country?->timezones_count ?? 0) <= 1;
                        }),
                ])
                ->columns(3),
        ]);
    }
}
