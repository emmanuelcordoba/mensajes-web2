<?php

namespace App\Services;

use App\Models\Cadete;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lo que la central hace con la cola de cadetes desde la pantalla de Inicio.
 *
 * Son las dos operaciones de la columna «Cadetes activos»: mandar a uno al final y vaciarla
 * entera. La entrada a la cola es otra cosa y vive en `IngresoALaCola`: ésa la dispara el
 * cadete desde el mostrador y tiene sus propias comprobaciones —bloqueo, cuota, estado—.
 * Acá el que opera es la oficina, que ya está autenticada.
 *
 * ⚠️ `orden_cola` es un timestamp Unix **en segundos**, no una posición. «Mandar al final»
 * es escribir la hora de ahora, que es posterior a la de cualquiera que ya esté en la cola.
 * Dos cadetes mandados al final dentro del mismo segundo quedan empatados y el orden entre
 * ellos lo decide la base; es así desde el sistema viejo y no se arregla acá, porque
 * cambiar la unidad de esa columna toca también la app y el mostrador.
 */
class ColaDeCadetes
{
    /**
     * Por qué no se puede vaciar la cola, o null si se puede.
     *
     * La única razón es que no haya nadie. El panel viejo escondía el botón con
     * `@if(count($cadetes_activos)>0)` y no comprobaba nada del lado del servidor: vaciar
     * una cola vacía recorría cero cadetes y contestaba que todo salió bien.
     */
    public static function porQueNoLimpiar(): ?string
    {
        return Cadete::query()->enLaCola()->exists() ? null : 'No hay nadie en la cola.';
    }

    /**
     * Por qué no se puede mandar a uno al final, o null si se puede.
     *
     * ⚠️ El sistema viejo no lo preguntaba: `finalDeLaCola()` hacía `findOrFail` y escribía
     * `orden_cola` sobre cualquier cadete, estuviera o no en la cola. Desde la pantalla no
     * se llegaba —el botón sólo está en las filas de la cola—, pero el endpoint estaba
     * abierto a cualquiera del panel con un id.
     */
    public static function porQueNoMandarAlFinal(Cadete $cadete): ?string
    {
        return $cadete->estaActivo() && $cadete->orden_cola !== null
            ? null
            : 'El móvil '.$cadete->numero_movil.' no está en la cola.';
    }

    public function mandarAlFinal(Cadete $cadete): void
    {
        $motivo = self::porQueNoMandarAlFinal($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que la pantalla aceptó algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        $cadete->orden_cola = now()->getTimestamp();
        $cadete->save();
    }

    /**
     * Por qué no se puede sacar a uno de la cola, o null si se puede.
     *
     * Igual que mandarlo al final: el `quitarCadeteCola()` viejo escribía `inactivo` sobre
     * cualquier cadete que le mandaran por id, estuviera o no en la cola.
     */
    public static function porQueNoSacar(Cadete $cadete): ?string
    {
        return $cadete->estaActivo()
            ? null
            : 'El móvil '.$cadete->numero_movil.' no está en la cola.';
    }

    /**
     * Lo saca de la cola: queda inactivo.
     *
     * Es el botón «Quitar» del detalle. Escribe el estado derecho en vez de alternarlo con
     * `alternarEstadoDesdeLaCentral()`: el que lo aprieta está mirando un diálogo que puede
     * tener medio minuto, y alternar sobre un estado que cambió mientras tanto haría lo
     * contrario de lo que dice el botón.
     */
    public function sacar(Cadete $cadete): void
    {
        $motivo = self::porQueNoSacar($cadete);

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        $cadete->estado = Cadete::ESTADO_INACTIVO;
        $cadete->save();
    }

    /**
     * Saca a todos de la cola y devuelve cuántos salieron.
     *
     * ⚠️ Uno por uno y no con un `update()` masivo, igual que el sistema viejo aunque por
     * otra razón: el modelo cierra el tramo de actividad abierto en su evento `saving`, y
     * una actualización masiva no dispara eventos. Con un `update()` los tramos de los que
     * estaban en la app quedarían abiertos para siempre.
     *
     * En una transacción porque son N escrituras que valen como una sola decisión: o sale
     * toda la cola o no sale nadie.
     */
    public function limpiar(): int
    {
        $motivo = self::porQueNoLimpiar();

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        return DB::transaction(function (): int {
            $cadetes = Cadete::query()->enLaCola()->get();

            foreach ($cadetes as $cadete) {
                $cadete->estado = Cadete::ESTADO_INACTIVO;
                $cadete->save();
            }

            return $cadetes->count();
        });
    }
}
