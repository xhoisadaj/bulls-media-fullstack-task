<?php

namespace App\Providers\Filament;

use App\Http\Middleware\UseUserTimezone;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Sheets Sync')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->navigationGroups([
                NavigationGroup::make('Review'),
                NavigationGroup::make('Sources'),
                NavigationGroup::make('Activity'),
                NavigationGroup::make('Settings'),
            ])
            ->maxContentWidth(Width::Full)
            // Every form save and button runs in one database transaction: all or nothing.
            // Schema changes (ALTER/CREATE/DROP TABLE) commit implicitly in MySQL, so they run
            // right after the commit (DB::afterCommit) or in actions that opt out explicitly.
            ->databaseTransactions()
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.styles'))
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.attention-banner'))
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                UseUserTimezone::class,
            ]);
    }
}
