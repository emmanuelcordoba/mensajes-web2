<?php

namespace App\Providers\Filament;

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
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * El panel de administración de Filament, en /admin.
 *
 * Es una herramienta sobre las tablas, aparte del panel de /panel con el que trabaja la
 * oficina. Los dos salen de la misma base y de los mismos modelos, pero no comparten
 * pantallas: /panel tiene las reglas del negocio escritas en controladores y políticas,
 * y esto es acceso directo a las filas.
 *
 * ⚠️ Quién entra lo decide `User::canAccessPanel()`, no este archivo, y sin ese método
 * Filament deja pasar a cualquier usuario autenticado mientras APP_ENV sea `local`. Ver
 * su docblock.
 *
 * ⚠️ Y NO SE HABILITA `->login()`, que es lo que deja puesto el instalador. La
 * aplicación ya tiene una pantalla de ingreso —la de Fortify, en /login— y dos
 * pantallas para las mismas credenciales son dos lugares donde arreglar lo mismo y una
 * forma segura de confundir a la gente. Sin `->login()`, el middleware de Filament
 * manda a /login como cualquier otra ruta protegida.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
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
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // ⚠️ El email verificado NO viene en la lista del instalador, y sin esto
                // /admin quedaba más abierto que /panel: las rutas del panel van detrás
                // de ['auth','verified'], así que una cuenta sin verificar no entra ahí
                // pero sí entraba acá, que es donde se tocan las filas directamente.
                EnsureEmailIsVerified::class,
            ]);
    }
}
