<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditStoreProfile;
use App\Filament\Widgets\CustomerStatsWidget;
use App\Filament\Widgets\SaleStatsWidget;
use App\Filament\Widgets\StockLevelsAlerts;
use App\Filament\Widgets\SupplierStatsWidget;
use App\Http\Middleware\EnsurePosSystemUserAuthenticated;
use App\Http\Middleware\EnsureStoreBootstrapReady;
use App\Http\Middleware\SyncCloudStoreOnTenantSwitch;
use App\Models\Store;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use SmartTill\Core\Http\Middleware\SetTenantTimezone as CoreSetTenantTimezone;

class StorePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('store')
            ->path('store')
            ->viteTheme('resources/css/filament/store/theme.css')
            ->userMenu(false)
            ->tenant(Store::class)
            ->tenantProfile(EditStoreProfile::class)
            ->navigationGroups([
                'Sales & Transactions',
                'Inventory',
                'Purchases',
                'Reports',
                'Settings',
            ])
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(
                in: base_path('vendor/smart-till/core/src/Filament/Resources'),
                for: 'SmartTill\\Core\\Filament\\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(
                in: base_path('vendor/smart-till/core/src/Filament/Pages'),
                for: 'SmartTill\\Core\\Filament\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                SaleStatsWidget::class,
                class_exists(\SmartTill\Core\Filament\Widgets\CustomerStatsWidget::class)
                    ? \SmartTill\Core\Filament\Widgets\CustomerStatsWidget::class
                    : CustomerStatsWidget::class,
                class_exists(\SmartTill\Core\Filament\Widgets\SupplierStatsWidget::class)
                    ? \SmartTill\Core\Filament\Widgets\SupplierStatsWidget::class
                    : SupplierStatsWidget::class,
                StockLevelsAlerts::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsurePosSystemUserAuthenticated::class,
                SyncCloudStoreOnTenantSwitch::class,
                EnsureStoreBootstrapReady::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                CoreSetTenantTimezone::class,
            ], isPersistent: true)
            ->databaseNotifications(isLazy: false)
            ->databaseNotificationsPolling(null)
            ->spa()
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): View => view('filament.store.partials.cloud-sync-status'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.store.partials.log-viewer-shortcut'),
            );
    }
}
