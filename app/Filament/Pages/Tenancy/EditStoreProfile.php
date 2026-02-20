<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        ->maxLength(255),
                ]),
        ]);
    }
}
