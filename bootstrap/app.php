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

        /*
        | Cuántos pedidos por minuto aguanta la API.
        |
        | ⚠️ **Son los 120 del sistema viejo** —`throttle.user:120,1` en su Kernel—, y acá
        | faltaban: el grupo `api` del esqueleto de Laravel no trae ninguno, así que las tres
        | rutas públicas estaban sin tope. Una de ellas, la postulación, acepta cuatro
        | imágenes de hasta 4 MB cada una sin pedir token.
        |
        | ⚠️ El sistema viejo necesitaba una subclase propia —`ThrottleRequestsByUser`— porque
        | Passport no resolvía el usuario antes de que corriera `auth:api`. El `throttle` del
        | framework ya hace lo mismo: cuenta por id cuando hay cuenta y por IP cuando no.
        */
        $middleware->api(prepend: ['throttle:120,1']);

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
