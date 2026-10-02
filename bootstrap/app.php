<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerificarRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
        | A dónde va alguien que ya entró y vuelve a pedir /login o /register.
        |
        | ⚠️ Es un segundo lugar que decide lo mismo que config/fortify.php, y por eso
        | lee de ahí en vez de repetir la ruta: Fortify manda a su `home` después de
        | entrar, pero a quien YA está adentro lo redirige RedirectIfAuthenticated, que
        | es de Laravel y no mira esa configuración. Sin esta línea, el que ya entró
        | caía en /dashboard aunque el login mandara al panel.
        */
        $middleware->redirectUsersTo(fn () => config('fortify.home'));

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Se usa como `rol:admin` o `rol:empleado,admin`, igual que en el panel viejo.
        $middleware->alias([
            'rol' => VerificarRol::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
