<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditStoreProfile;
use App\Filament\Pages\Tenancy\RegisterStore;
use App\Models\Store;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class StorePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('store')
            ->path('store')
            ->login()
            ->registration()
            ->profile()
            ->tenant(Store::class)
            ->tenantRegistration(RegisterStore::class)
            ->tenantProfile(EditStoreProfile::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(
                in: base_path('vendor/smart-daddy/catalog-category/src/Filament/Resources'),
                for: 'SmartDaddy\\CatalogCategory\\Filament\\Resources'
            )
            ->discoverResources(
                in: base_path('vendor/smart-daddy/catalog-brand/src/Filament/Resources'),
                for: 'SmartDaddy\\CatalogBrand\\Filament\\Resources'
            )
            ->discoverResources(
                in: base_path('vendor/smart-daddy/catalog-unit/src/Filament/Resources'),
                for: 'SmartDaddy\\CatalogUnit\\Filament\\Resources'
            )
            ->discoverResources(
                in: base_path('vendor/smart-daddy/catalog-attribute/src/Filament/Resources'),
                for: 'SmartDaddy\\CatalogAttribute\\Filament\\Resources'
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
