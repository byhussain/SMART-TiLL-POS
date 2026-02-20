<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Store;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RegisterStore extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create store';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Store name')
                ->required()
                ->maxLength(255),
        ]);
    }

    protected function handleRegistration(array $data): Store
    {
        /** @var User $user */
        $user = auth()->user();

        $store = Store::query()->create($data);
        $user->stores()->attach($store);

        return $store;
    }

    protected function getFormActions(): array
    {
        $actions = [
            $this->getRegisterFormAction(),
        ];

        if ($this->shouldShowCancelAction()) {
            $actions[] = Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->url(route('filament.store.tenant'));
        }

        return $actions;
    }

    protected function shouldShowCancelAction(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->stores()->exists()) {
            return false;
        }

        $referer = request()->headers->get('referer');

        if (is_string($referer) && Str::contains($referer, '/store/register')) {
            return false;
        }

        return true;
    }
}
