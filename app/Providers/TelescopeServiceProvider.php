<?php

namespace App\Providers;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope: qué se guarda de lo que pasa en el sistema, y quién puede mirarlo.
 *
 * Está para contestar una pregunta que el monitoreo de Laravel Cloud no contesta. Esa
 * pantalla dice cuántas respuestas 4XX y 5XX hubo y en qué ruta —187 en un día sobre
 * `/api/actualizarubicacion`, y 2,8K errores de servidor el día anterior—, pero no de
 * quién, ni con qué cuerpo, ni qué excepción. Y con el plan que tenemos **sólo se puede
 * mirar el último día**: lo de anteayer ya no está.
 *
 * ⚠️ **Guarda únicamente lo que falla.** Es el filtro de producción que trae Laravel, y
 * sin él esto sería un registro por pedido sobre 16K diarios. Con él son los ~190 que hoy
 * fallan, y 5,5K en un día malo: nada. Lo que se guarda de cada uno es lo que hace falta
 * para entender por qué falló —el cadete, el cuerpo, la traza—, que es justamente lo que
 * después no se puede reconstruir.
 *
 * ⚠️ **En local se guarda todo**, que es para lo que Telescope fue hecho.
 *
 * ⚠️ Los watchers pesados están apagados en `config/telescope.php`. No alcanza con que el
 * filtro los descarte: lo que se recolecta se paga en cada pedido aunque después se tire.
 *
 * ⚠️ Esto crece y hay que podarlo: `telescope:prune` corre todos los días desde
 * `routes/console.php` y deja treinta días, contra el día que da el plan de Cloud.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $esLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($esLocal): bool {
            return $esLocal
                || $entry->isReportableException()
                // Toda respuesta de 400 para arriba, que es lo que vinimos a entender.
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag()
                // Y lo que el sistema escribe a mano cuando algo no cierra, como el
                // renglón del tope de pedidos de `bootstrap/app.php`.
                || self::esUnLogQueImporta($entry);
        });

        Telescope::tag(fn (IncomingEntry $entry): array => self::etiquetas($entry));
    }

    /**
     * Un log de `warning` para arriba.
     *
     * Los de `info` y `debug` son el ruido normal de un sistema andando; estos son los que
     * alguien escribió porque algo no estaba bien.
     */
    protected static function esUnLogQueImporta(IncomingEntry $entry): bool
    {
        return $entry->type === 'log'
            && in_array($entry->content['level'] ?? '', ['warning', 'error', 'critical', 'alert', 'emergency'], true);
    }

    /**
     * Por qué se va a buscar esto después.
     *
     * Telescope busca por etiqueta, así que acá se decide qué preguntas se van a poder
     * hacer: «todos los 429», «todo lo que falló del móvil 142». Sin esto hay que leer el
     * feed entero a ojo.
     *
     * @return list<string>
     */
    protected static function etiquetas(IncomingEntry $entry): array
    {
        $etiquetas = [];

        if ($entry->type === 'request') {
            $codigo = $entry->content['response_status'] ?? null;

            if ($codigo !== null) {
                $etiquetas[] = 'codigo:'.$codigo;
            }
        }

        $movil = self::movilDeQuienPide();

        if ($movil !== null) {
            $etiquetas[] = 'movil:'.$movil;
        }

        return $etiquetas;
    }

    /**
     * El móvil de quien hizo este pedido.
     *
     * ⚠️ **`withoutRecording` no es optativo: sin él esto es una recursión infinita que
     * termina en un segfault.** Buscar el cadete es una consulta, la consulta genera una
     * entrada de Telescope, la entrada llama a este callback, que vuelve a consultar. El
     * servidor se cae sin dejar ni una línea en el log, y del otro lado lo único que se ve
     * es una respuesta vacía. Pasó al instalar esto, en la pantalla de ingreso.
     *
     * ⚠️ **Y no se recuerda la respuesta de la primera vez.** El callback corre por cada
     * entrada, y la primera de un pedido puede ser anterior a que se resuelva el token
     * —es, justamente, la consulta que lo resuelve—: ahí todavía no hay nadie, y guardar
     * ese `null` deja sin móvil a todo el pedido. Es el error que tuvo esta función
     * primero, y por eso ninguna falla aparecía con su móvil. Repetir no cuesta: la
     * cuenta es siempre el mismo objeto y Eloquent ya se guarda su cadete.
     */
    protected static function movilDeQuienPide(): ?int
    {
        return Telescope::withoutRecording(function (): ?int {
            $cuenta = request()->user();

            return $cuenta instanceof User ? $cuenta->cadete?->numero_movil : null;
        });
    }

    /**
     * Lo que no se guarda ni aunque el pedido falle.
     *
     * ⚠️ `authorization` **no viene en la lista de fábrica**, y por ahí pasa el token de
     * cada cadete en cada pedido de la app: guardarlo es dejar la llave de la cuenta
     * escrita en una tabla que mira la oficina.
     *
     * ⚠️ Y el cuerpo de la postulación trae el DNI y las fotos de alguien que todavía no
     * entró a trabajar. Un 422 en esa ruta es de los más probables que hay —es un
     * formulario largo— y no hay ninguna razón para que ese dato quede guardado un mes
     * para entender un error de validación.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            // El código de un solo uso de recuperar contraseña.
            'codigo',
            // Y lo que manda la postulación: el DNI y sus cuatro fotos.
            'dni',
            'foto',
            'dni_frente',
            'dni_dorso',
            'boleta_de_servicio',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Quién puede entrar a `/telescope`.
     *
     * ⚠️ Sólo administradores, y es el mismo recorte que el listado de cadetes dados de
     * baja: acá se ven cuerpos de pedidos de todo el sistema. La lista de correos que trae
     * el archivo de fábrica no sirve en un sistema donde los roles ya están en la base.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => $user->tieneRol(Rol::ADMIN));
    }
}
