<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /** Los 120 del sistema viejo: `throttle.user:120,1` en su Kernel. */
    public const PEDIDOS_POR_MINUTO = 120;

    /** Cuántas ubicaciones por minuto, que tienen su propio balde. */
    public const UBICACIONES_POR_MINUTO = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiTokens();
        $this->configureSchema();
        $this->configureRateLimiting();
    }

    /**
     * Cuántos pedidos por minuto aguanta la API, y cuántas ubicaciones.
     *
     * ⚠️ **Los 120 generales no alcanzaban, y el problema no era el número.** El 17 y el 18
     * de noviembre un solo teléfono —móvil 1716, un Android 12— mandó hasta **817
     * ubicaciones en un minuto**: 3.179 rechazos en 19 horas. El tope cuenta por cuenta, así
     * que ningún otro cadete se vio afectado, pero ese cadete **se comía su propio
     * presupuesto con ubicaciones** y después le rebotaban las cosas que importan:
     * `getcadete` 25 veces y `pedidos` 23. No podía ver ni aceptar un pedido por culpa de su
     * propio GPS.
     *
     * ⚠️ **Por eso la ubicación tiene su propio balde.** Cuando se llena, el pedido se cae
     * antes de tocar el balde general, así que el teléfono roto se queda sin mandar
     * ubicaciones y conserva intactos sus 120 para trabajar. Esto depende de un detalle del
     * middleware: `ThrottleRequests::handleRequest()` comprueba **todos** los límites en un
     * primer recorrido y recién después suma en un segundo, así que basta con devolver los
     * dos límites para que el excedido corte sin incrementar al otro.
     *
     * ⚠️ Diez por minuto es generoso para un cliente sano: la app manda cada 50 metros con
     * un freno de 15 segundos, o sea unas cuatro por minuto como techo.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $peticion): array {
            // Por cuenta cuando hay token, y por IP cuando no: las tres rutas públicas de
            // la app del cadete —login, postulación y el reseteo— entran sin cuenta, y una
            // de ellas acepta cuatro imágenes de hasta 4 MB.
            $quien = $peticion->user()?->getAuthIdentifier() ?? $peticion->ip();

            $limites = [];

            if ($peticion->routeIs('api.cadete-app.v1.ubicacion')) {
                $limites[] = Limit::perMinute(self::UBICACIONES_POR_MINUTO)
                    ->by('ubicacion|'.$quien);
            }

            $limites[] = Limit::perMinute(self::PEDIDOS_POR_MINUTO)->by('api|'.$quien);

            return $limites;
        });
    }

    /**
     * Keep PostgreSQL's microsecond precision on dates and times, as ESQUEMA.sql
     * defines them. Laravel defaults to whole seconds, and a TIMESTAMPTZ(0)
     * column rounds the milliseconds of the migrated MongoDB dates: 23:59:59.6
     * on the last day of a month would move to the next month.
     */
    protected function configureSchema(): void
    {
        Schema::defaultTimePrecision(null);
    }

    /**
     * Make API tokens expire by inactivity. See config/sanctum.php.
     */
    protected function configureApiTokens(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
