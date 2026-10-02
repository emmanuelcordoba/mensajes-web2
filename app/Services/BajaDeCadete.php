<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dar de baja un cadete, con lo que corresponde y sin lo que no.
 *
 * Es la regla del `destroy` de cadetes del panel viejo, traída entera. Lo interesante no
 * es lo que se lleva sino lo que deja.
 *
 * ⚠️ LOS PEDIDOS NO SE TOCAN, y es la diferencia con dar de baja un cliente. Los pedidos
 * de un cliente son suyos; los de un cadete son pedidos **de otros** que él entregó, con
 * su `valor` y su `garantia`. Borrarlos sería borrar facturación e historial ajeno —el
 * cadete con más entregas tiene 11.004—. Se conserva `cadete_id`, que sigue resolviendo
 * con `withTrashed()` para los reportes, y las pantallas de pedido ya toleran un cadete
 * nulo.
 *
 * ⚠️ `actividad_cadetes` y `cobranza_saldo_movimientos` tampoco se tocan, y por una razón
 * distinta: **ninguno de los dos modelos usa SoftDeletes**, así que borrarlos sería
 * físico e irreversible. Y los movimientos de cobranza son registros de plata
 * efectivamente cobrada.
 *
 * El orden —mensajes, postulación, cuenta, cadete— es el mismo criterio que en clientes:
 * lo accesorio primero y el cadete al final, para que un fallo a mitad de camino lo deje
 * visible y reintentable en vez de huérfano e invisible.
 */
class BajaDeCadete
{
    /**
     * Por qué no se puede dar de baja, o null si se puede.
     *
     * ⚠️ Un cadete con la cobranza abierta no se da de baja: la fila quedaría invisible
     * para Eloquent y **nadie volvería a reclamar esa plata**. Hay que cerrar la cobranza
     * primero.
     *
     * Los montos se comparan como cadenas con `bccomp` y no como float: son
     * `NUMERIC(12,2)` y convertirlos a float es justo donde se pierden los centavos de
     * una deuda. Acá sólo se mira el signo, pero la disciplina es la misma en todo el
     * sistema y no cuesta nada sostenerla.
     */
    public static function porQueNo(Cadete $cadete): ?string
    {
        $deuda = self::monto($cadete->monto_deuda);
        $saldo = self::monto($cadete->cobranza_saldo);

        $motivos = [];

        if (bccomp($deuda, '0', 2) > 0) {
            $motivos[] = 'una deuda de $'.$deuda;
        }

        if (bccomp($saldo, '0', 2) !== 0) {
            // El saldo baja a medida que toma pedidos, así que uno positivo es crédito a
            // favor del cadete y uno negativo es plata que debe.
            $motivos[] = 'un saldo de $'.$saldo.(bccomp($saldo, '0', 2) > 0 ? ' a favor' : ' en contra');
        }

        if ($motivos === []) {
            return null;
        }

        return 'El móvil '.$cadete->numero_movil.' tiene '.implode(' y ', $motivos)
            .'. Cerrá la cobranza antes de darlo de baja.';
    }

    /**
     * Un monto como cadena, lista para `bccomp`.
     *
     * La columna es `NUMERIC(12,2)` y lo que llega es siempre un número escrito, pero el
     * tipo declarado es `string` a secas. `is_numeric()` lo confirma en vez de suponerlo,
     * y de paso un valor corrupto no revienta la comparación.
     *
     * @return numeric-string
     */
    protected static function monto(?string $valor): string
    {
        return $valor !== null && is_numeric($valor) ? $valor : '0';
    }

    /**
     * Qué se lleva y qué se queda, para poder decirlo antes de hacerlo.
     *
     * @return array{mensajes: int, postulacion: bool, cuenta: string|null, pedidos: int}
     */
    public static function queArrastra(Cadete $cadete): array
    {
        return [
            'mensajes' => Mensaje::query()->where('cadete_id', $cadete->id)->count(),
            'postulacion' => $cadete->postulacion !== null,
            'cuenta' => $cadete->user?->email,
            // No se borran: se informan para que quede claro que quedan.
            'pedidos' => $cadete->pedidos()->count(),
        ];
    }

    /**
     * La baja. Devuelve lo que se llevó.
     *
     * @return array{mensajes: int, postulacion: bool, cuenta: string|null}
     */
    public function dar(Cadete $cadete, ?User $autor = null): array
    {
        $motivo = self::porQueNo($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que el listado ofreció algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        // Masivo: hay 8.494 mensajes en total y una conversación larga no conviene
        // recorrerla como modelos.
        $mensajes = Mensaje::query()->where('cadete_id', $cadete->id)->delete();

        // La postulación es el legajo: si se va el cadete, no tiene sentido conservar sus
        // documentos de identidad sueltos. Es además lo más pesado de la base —557 MB en
        // 628 documentos—.
        $postulacion = $cadete->postulacion !== null;
        $cadete->postulacion?->delete();

        // Todo cadete tiene cuenta —`cadetes.user_id` es NOT NULL—, pero se comprueba
        // igual: el destroy viejo la desreferenciaba sin guarda y con una fila
        // inconsistente respondía 500.
        $cuenta = $cadete->user?->email;
        $cadete->user?->delete();

        $cadete->delete();

        Log::info('Cadete dado de baja desde /admin', [
            'cadete_id' => $cadete->id,
            'numero_movil' => $cadete->numero_movil,
            'mensajes' => $mensajes,
            'postulacion' => $postulacion,
            'cuenta' => $cuenta,
            'autor' => $autor?->email,
        ]);

        return ['mensajes' => $mensajes, 'postulacion' => $postulacion, 'cuenta' => $cuenta];
    }
}
