<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerificarRol;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        /*
        | Cada vez que alguien se pasa del tope, queda escrito quién.
        |
        | ⚠️ **Es lo único que contesta la pregunta de quién.** El monitoreo de Laravel
        | Cloud dice cuántos 429 hubo y en qué ruta —187 en un día sobre
        | `/api/actualizarubicacion`—, pero no de quién, y con el tope contando por cuenta
        | (ver `tests/Feature/Api/TopeDePedidosTest.php`) un 429 significa que ESE cadete
        | hizo 120 pedidos en un minuto. Sin el móvil no hay manera de saber cuál, ni si
        | son muchos cadetes o siempre el mismo teléfono con el GPS enloquecido.
        |
        | ⚠️ Devuelve null a propósito: esto escucha, no responde. El 429 que recibe la app
        | lo sigue armando Laravel, con sus cabeceras `Retry-After` y todo. Un `return`
        | distinto de null acá le cambiaría la respuesta a la app sin querer.
        |
        | ⚠️ Nada de datos personales: el id de la cuenta y el número de móvil, que son los
        | que sirven para buscar en el panel. Ni nombre, ni teléfono, ni el cuerpo.
        */
        $exceptions->render(function (ThrottleRequestsException $e, Request $peticion) {
            $cuenta = $peticion->user();

            Log::warning('Tope de pedidos superado', [
                'ruta' => $peticion->path(),
                'metodo' => $peticion->method(),
                'cuenta_id' => $cuenta?->getAuthIdentifier(),
                'movil' => $cuenta instanceof User ? $cuenta->cadete?->numero_movil : null,
                // Sin cuenta el tope cuenta por IP, así que ahí la IP es la única pista.
                'ip' => $peticion->ip(),
                'reintentar_en' => $e->getHeaders()['Retry-After'] ?? null,
            ]);

            return null;
        });
    })->create();
