<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            ->colors([
                'primary' => Color::Blue,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->icon('heroicon-o-briefcase'),
            ])
            ->navigationItems([
                NavigationItem::make('Сотрудники')
                    ->url('/admin/employees')
                    ->icon('heroicon-o-users')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(1),
                NavigationItem::make('Клиенты')
                    ->url('/clients')
                    ->icon('heroicon-o-user-group')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(2),
                NavigationItem::make('Свод руководителя')
                    ->url('/admin/manager-summary')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(3),
                NavigationItem::make('Фото рабочего времени')
                    ->url('/admin/work-photos')
                    ->icon('heroicon-o-camera')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(4),
                NavigationItem::make('БухСмета')
                    ->url('/admin/accounting-estimate')
                    ->icon('heroicon-o-calculator')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(5),
                NavigationItem::make('БухЗадачник')
                    ->url('/admin/accounting-tasks')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(6),
                NavigationItem::make('СВК')
                    ->url('/admin/svk')
                    ->icon('heroicon-o-shield-check')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(7),
                NavigationItem::make('CRM')
                    ->url('/admin/crm')
                    ->icon('heroicon-o-chart-pie')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(8)
                    ->childItems([
                        NavigationItem::make('Сделки')
                            ->url('/admin/crm/deals')
                            ->icon('heroicon-o-banknotes'),
                        NavigationItem::make('Контакты')
                            ->url('/admin/crm/contacts')
                            ->icon('heroicon-o-phone'),
                        NavigationItem::make('Задачи')
                            ->url('/admin/crm/tasks')
                            ->icon('heroicon-o-check-circle'),
                    ]),
                NavigationItem::make('Инструменты')
                    ->url('/admin/tools')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->group('РАБОЧЕЕ ПРОСТРАНСТВО')
                    ->sort(9),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => Blade::render('filament.components.trial-banner'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
            ]);
    }
}
