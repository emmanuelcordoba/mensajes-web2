<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Cómo el panel muestra un momento.
 *
 * ⚠️ FORMATEA EL SERVIDOR Y NO EL NAVEGADOR, y no es una preferencia: la primera
 * versión de la pantalla de un pedido mandaba la fecha en ISO y la formateaba en
 * React con `toLocaleString('es-AR')`. Abriéndola en el navegador, React avisó de un
 * desajuste de hidratación: el servidor había escrito **23:37** y el cliente
 * **20:37**. Tres horas, que son las que hay entre UTC —la zona del proceso que
 * renderiza— y `America/Argentina/Tucuman`, que es la de la aplicación.
 *
 * Ningún test lo habría visto: del lado de PHP se comprueban las props, y la hora la
 * armaba el navegador. Y la misma trampa tenía el listado, que calculaba «hace tanto»
 * con `Date.now()` — el otro caso que el aviso de React nombra, porque el reloj
 * avanza entre que el servidor renderiza y el cliente hidrata.
 *
 * Acá la zona está configurada y Carbon la respeta, así que el servidor manda la
 * cadena ya armada y el frontend sólo la imprime. No hay dos lugares que sepan la
 * zona ni dos que sepan el formato.
 *
 * ⚠️ Lo que se pierde: «hace 40 min» no avanza solo, queda como estaba al cargar la
 * página. Para una pantalla que la oficina recarga todo el tiempo alcanza, y es lo
 * que hace el sistema viejo. Si alguna vez hace falta que corra, lo que corresponde
 * es recalcularlo en el cliente **después** de montar, no al renderizar.
 */
class Momento
{
    /** Empieza en domingo porque `dayOfWeek` devuelve 0 para el domingo. */
    private const DIAS_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    /**
     * Fecha y hora completas, en 24 horas.
     *
     * ⚠️ 24 y no 12: la pantalla vieja usaba `format('d-m-Y h:i:s')`, y `h` es la
     * hora de 12 **sin am/pm**, así que todas las horas de ese historial son
     * ambiguas —13:45 y 01:45 se ven iguales—. En un historial de estados eso es
     * justo lo que se mira para entender qué pasó.
     */
    public static function fechaYHora(?CarbonInterface $momento): ?string
    {
        // ⚠️ `setTimezone` explícito: `format()` usa la zona que trae el objeto, no la
        // de la aplicación. Un Carbon que venga en UTC —de `Carbon::parse()` sobre una
        // cadena terminada en Z, por ejemplo— se escribía tres horas adelantado. Es el
        // mismo error que este archivo existe para arreglar, un nivel más abajo, y lo
        // encontró el test que se escribió para comprobar justamente esto.
        //
        // `copy()` porque el Carbon es de quien llama: cambiarle la zona de paso sería
        // un efecto que nadie espera.
        return $momento?->copy()->setTimezone((string) config('app.timezone'))->format('d/m/Y H:i');
    }

    /**
     * Una fecha y hora como las escribe la API vieja, para las apps.
     *
     * ⚠️ **Este formato es parte del contrato con las apps y se midió, no se eligió.** La
     * app del cadete imprime `{{ pedido.created_at }}` y `{{ mensaje.created_at }}` crudos
     * en la pantalla, así que lo que salga de acá es literalmente lo que el cadete lee. El
     * sistema viejo emite `Y-m-d H:i:s` porque `jenssegers/mongodb` sobreescribe
     * `getDateFormat()` con eso y Laravel 6 lo usa al serializar. Devolver el ISO-8601 que
     * usa Laravel por omisión le pondría al cadete «2026-10-07T15:34:56.000000Z» en
     * pantalla, y encima en UTC.
     *
     * ⚠️ Y en la zona de la aplicación, por lo mismo que `fechaYHora()`: `format()` usa la
     * zona que trae el objeto, no la de la aplicación. Las dos aplicaciones están en
     * `America/Argentina/Tucuman`.
     */
    public static function enApi(?CarbonInterface $momento): ?string
    {
        return $momento?->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * Sólo la fecha, sin hora.
     *
     * Para una fecha de nacimiento, que es un día y no un instante: mostrarla con
     * hora invita a preguntarse de qué zona es esa medianoche.
     */
    public static function fecha(?CarbonInterface $momento): ?string
    {
        return $momento?->copy()->setTimezone(config('app.timezone'))->format('d/m/Y');
    }

    /**
     * El nombre corto del día de la semana: «lun», «mar».
     *
     * Escrito y no traducido, por lo mismo que `hace()`: `isoFormat('ddd')` devuelve
     * «Tue» o «mar» según cómo esté cargado el locale de Carbon en el proceso, y eso
     * no es algo que esta clase controle. Lo usa la tabla de estadísticas, donde el
     * día de la semana es lo que explica los ceros.
     */
    public static function diaCorto(CarbonInterface $momento): string
    {
        return self::DIAS_CORTOS[$momento->dayOfWeek];
    }

    /**
     * Hace cuánto, en palabras.
     *
     * A mano y no con `diffForHumans()`: ése depende de que el locale de Carbon esté
     * cargado y devuelve «hace 40 minutos» o «40 minutes ago» según cómo esté el
     * proceso. Son cuatro casos y así dicen siempre lo mismo.
     */
    public static function hace(?CarbonInterface $momento): ?string
    {
        if ($momento === null) {
            return null;
        }

        $minutos = (int) $momento->diffInMinutes(now(), true);

        if ($minutos < 1) {
            return 'ahora';
        }

        if ($minutos < 60) {
            return "hace {$minutos} min";
        }

        $horas = intdiv($minutos, 60);

        if ($horas < 24) {
            return "hace {$horas} h";
        }

        return 'hace '.intdiv($horas, 24).' d';
    }
}
