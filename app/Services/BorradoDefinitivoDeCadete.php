<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\LogEstadoPedido;
use App\Models\Mensaje;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sacar del padrón, de verdad, a un cadete que ya está dado de baja.
 *
 * Es el borrado definitivo del listado de dados de baja. En el sistema viejo esta
 * operación vive en las pantallas de Corrección de datos —previas a la migración— y el
 * listado de bajas la ofrece por un enlace; acá las correcciones no existen, así que la
 * confirmación es del propio listado y lo que escribe es esto.
 *
 * ⚠️ **ES LO ÚNICO QUE SE OFRECE SOBRE UN DADO DE BAJA, y no se deshace.** La baja lógica
 * ya pasó: ofrecerla de nuevo no haría nada. Lo que falta es la otra mitad —sacar la fila—,
 * y eso es lo que hace esto.
 *
 * ⚠️ **LOS PEDIDOS NO SE BORRAN**, igual que en la baja: son pedidos de otros que esta
 * persona entregó, con su valor y su garantía. Lo que se hace es dejarlos sin cadete. Es
 * la diferencia de fondo con `BajaDeCadete`, que no toca nada de los pedidos porque la
 * fila del cadete sigue ahí y `withTrashed()` la resuelve.
 *
 * Qué pasa con cada cosa:
 *
 * | Dónde                            | Qué se hace | Por qué                                         |
 * |----------------------------------|-------------|-------------------------------------------------|
 * | `pedidos.cadete_id` y `user_id`  | se anulan   | el pedido es de un cliente; queda sin cadete    |
 * | `logs_estados_pedidos`           | se anulan   | nullable; el log sigue siendo del pedido        |
 * | `actividades_cadetes`            | se borran   | sin cadete no dicen nada, y la columna es NOT NULL |
 * | `mensajes`                       | se borran   | la conversación es con ese cadete               |
 * | `postulaciones` y sus documentos | se borran   | es su legajo, con los documentos de identidad   |
 * | `cobranza_saldo_movimientos`     | se borran   | ver abajo                                       |
 * | su cuenta, sus fotos, sus passkeys | se borran | una cuenta de cadete sin cadete no sirve        |
 *
 * ⚠️ **LOS BORRADOS SON FÍSICOS, Y HAY QUE ESCRIBIRLOS ASÍ.** `Mensaje`, `Postulacion`,
 * `Pedido` y `User` usan SoftDeletes, así que la baja los dejó dados de baja y no
 * borrados; un `delete()` acá no sacaría la fila y la clave foránea seguiría apuntando a
 * un cadete que ya no está. Por eso cada paso es `forceDelete()` y va con `withTrashed()`.
 *
 * ⚠️ **Y los movimientos de cobranza se van con él.** Son registros de plata cobrada, y
 * conservarlos sería lo correcto si quedaran apuntando a alguien; apuntando a una fila que
 * no existe no sirven de nada, y `cobranza_saldo_movimientos.cadete_id` es NOT NULL, así
 * que la base no los dejaría quedarse. Es la misma decisión que se tomó allá el 2026-10-28.
 * Lo que queda es el registro del log, que dice cuántos eran.
 *
 * ⚠️ **Todo en una transacción**, que es lo que acá se puede y en el sistema viejo no
 * —MongoDB corre standalone—. Allá el orden importaba, porque un corte a mitad de camino
 * tenía que dejar al cadete visible y reintentable; acá o pasa todo o no pasa nada.
 */
class BorradoDefinitivoDeCadete
{
    /**
     * Por qué no se puede borrar definitivamente, o null si se puede.
     *
     * Son dos cosas distintas:
     *
     * - **Que no esté dado de baja.** El borrado definitivo es sólo para quien ya salió del
     *   padrón. Sobre un cadete que está trabajando no se ofrece ni se acepta.
     * - **Que su cuenta esté en uso por otra cosa.** La cuenta se va con el cadete, y si
     *   figura en un comercio, en mensajes de otro cadete o en cobranzas de otro cadete,
     *   esos registros quedarían sin cuenta. Es el único bloqueo que queda del sistema
     *   viejo, y es el que protege de verdad.
     *
     * ⚠️ La cobranza abierta NO bloquea acá, y sí bloquea la baja. Son dos momentos: la
     * baja se niega con plata en el medio porque la fila se vuelve invisible y nadie
     * volvería a reclamar esa plata. Para llegar hasta acá esa cobranza ya se cerró.
     */
    public static function porQueNo(Cadete $cadete): ?string
    {
        if (! $cadete->trashed()) {
            return 'El móvil '.$cadete->numero_movil.' no está dado de baja. El borrado definitivo es sólo para los que ya salieron del padrón.';
        }

        $cuantos = self::loQueUsaLaCuenta($cadete);

        if ($cuantos > 0) {
            return 'La cuenta del móvil '.$cadete->numero_movil.' figura en '.$cuantos
                .' registros que no son de este cadete (un comercio, o mensajes o cobranzas de otro), que quedarían sin cuenta.';
        }

        return null;
    }

    /**
     * Qué se lleva el borrado, para poder decirlo antes de apretarlo.
     *
     * Son los pedidos —que no se borran y quedan sin cadete— y lo que sí desaparece. La
     * cuenta se nombra por su email, que es como la oficina la reconoce.
     *
     * @return array{pedidos: int, mensajes: int, actividades: int, cobranza: int, postulacion: bool, cuenta: string|null}
     */
    public static function queArrastra(Cadete $cadete): array
    {
        return [
            // No se borran: se informan para que quede claro que quedan, sin cadete.
            'pedidos' => Pedido::withTrashed()->where('cadete_id', $cadete->id)->count(),
            'mensajes' => Mensaje::withTrashed()->where('cadete_id', $cadete->id)->count(),
            'actividades' => $cadete->actividades()->count(),
            'cobranza' => MovimientoCobranzaSaldo::query()->where('cadete_id', $cadete->id)->count(),
            'postulacion' => Postulacion::withTrashed()->where('cadete_id', $cadete->id)->exists(),
            // ⚠️ `withTrashed()`: la cuenta se dio de baja con el cadete, así que la
            // relación normal diría que no tiene, justo en el caso normal.
            'cuenta' => User::withTrashed()->find($cadete->user_id)?->email,
        ];
    }

    /**
     * El borrado. Devuelve cuántas filas tocó cada cosa.
     *
     * @return array{pedidos_sin_cadete: int, logs_sin_cadete: int, mensajes: int, actividades: int, cobranza: int, postulacion: int, cuenta: bool}
     *
     * @throws RuntimeException Si la regla lo niega.
     */
    public function ejecutar(Cadete $cadete, ?User $autor = null): array
    {
        $motivo = self::porQueNo($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que el listado ofreció algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        $movil = $cadete->numero_movil;
        $usuario = User::withTrashed()->find($cadete->user_id);

        $resultado = DB::transaction(function () use ($cadete, $usuario): array {
            // Los pedidos quedan, sin cadete y sin la cuenta que los cargó. `withTrashed()`
            // también acá: un pedido borrado sigue siendo una fila con la clave foránea.
            $pedidos = Pedido::withTrashed()->where('cadete_id', $cadete->id)->update(['cadete_id' => null]);

            if ($usuario !== null) {
                Pedido::withTrashed()->where('user_id', $usuario->id)->update(['user_id' => null]);
            }

            // El log sigue siendo del pedido: lo que se pierde es de quién fue el cambio.
            $logs = LogEstadoPedido::query()->where('cadete_id', $cadete->id)->update(['cadete_id' => null]);

            if ($usuario !== null) {
                LogEstadoPedido::query()->where('user_id', $usuario->id)->update(['user_id' => null]);
            }

            // ⚠️ `forceDelete()` y `withTrashed()`, que es lo que de verdad saca la fila: un
            // `delete()` sobre estos modelos es una baja lógica, y la clave foránea
            // seguiría apuntando al cadete.
            $mensajes = Mensaje::withTrashed()->where('cadete_id', $cadete->id)->forceDelete();
            $postulacion = Postulacion::withTrashed()->where('cadete_id', $cadete->id)->forceDelete();
            // Estos dos no usan SoftDeletes: `delete()` ya es físico.
            $cobranza = MovimientoCobranzaSaldo::query()->where('cadete_id', $cadete->id)->delete();
            $actividades = $cadete->actividades()->delete();

            $cadete->forceDelete();

            // La cuenta al final, porque `cadetes.user_id` es NOT NULL y hasta acá la fila
            // del cadete todavía la estaba apuntando. Sus fotos y sus passkeys se van con
            // ella por la clave foránea.
            $usuario?->forceDelete();

            return [
                'pedidos_sin_cadete' => $pedidos,
                'logs_sin_cadete' => $logs,
                'mensajes' => $mensajes,
                'actividades' => $actividades,
                'cobranza' => $cobranza,
                'postulacion' => $postulacion,
                'cuenta' => $usuario !== null,
            ];
        });

        // ⚠️ `warning` y no `info`: es lo único del panel que saca filas de verdad, y si
        // alguien pregunta después qué pasó con el móvil tal, esto es lo que queda.
        Log::warning('Borrado definitivo de cadete', [
            'numero_movil' => $movil,
            'cadete_id' => $cadete->id,
            'cuenta' => $usuario?->email,
            'autor' => $autor?->email,
            'resultado' => $resultado,
        ]);

        return $resultado;
    }

    /**
     * En cuántos registros que no son de este cadete figura su cuenta.
     *
     * ⚠️ Los mensajes y los movimientos **de este cadete** se descuentan: se borran con
     * él, así que contarlos acá bloquearía por la otra puerta todos los borrados.
     */
    protected static function loQueUsaLaCuenta(Cadete $cadete): int
    {
        $usuario = $cadete->user_id;

        return Cliente::withTrashed()->where('user_id', $usuario)->count()
            + Mensaje::withTrashed()
                ->where('user_id', $usuario)
                ->where('cadete_id', '!=', $cadete->id)
                ->count()
            + MovimientoCobranzaSaldo::query()
                ->where('user_id', $usuario)
                ->where('cadete_id', '!=', $cadete->id)
                ->count();
    }
}
