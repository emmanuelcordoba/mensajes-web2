<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\MovimientoCobranzaSaldo;
use Illuminate\Database\Eloquent\Collection;

/**
 * Una página de movimientos del saldo de un cadete, y si queda algo más atrás.
 *
 * Es lo que la pantalla de Cobranza de la app pide dos veces: al abrirse trae los 10 más
 * nuevos, y cada «Cargar más» trae 5. Los dos endpoints del sistema viejo —`/cobranza` y
 * `/cobranza/saldo/movimientos`— contestan lo mismo, así que las dos preguntas se hacen acá.
 *
 * ⚠️ **«Hay más» se contesta pidiendo uno de más.** El sistema viejo lo hace así en uno de
 * los dos endpoints y en el otro escribe
 * `count($cadete->movimientosCobranzaSaldo) > 10`: eso trae TODOS los movimientos del cadete
 * a memoria —1.173 para el que más tiene— para informar un booleano, y encima en una segunda
 * consulta, porque usa la propiedad y no el método. Comparar el tamaño de la página contra
 * el límite tampoco sirve: daría «hay más» cada vez que la página viene completa, aunque sea
 * la última. Una pregunta, un lugar.
 *
 * ⚠️ **El paginado es por `offset`, y eso lo manda la app**: llama con
 * `getCobranzaMovimientos(this.movimientos.length)`. Tiene una consecuencia que no se puede
 * arreglar desde el servidor: si al cadete le finalizan un pedido entre una página y la
 * siguiente, todo se corre un lugar y un movimiento aparece dos veces. Cambiarlo por un
 * cursor pide republicar la app.
 *
 * ⚠️ **El desempate por `id` es lo que sí se puede arreglar.** `created_at DESC` a secas
 * deja el orden de los empates a criterio de la base, y con `offset` eso es una página que
 * se solapa con la anterior. Hoy no hay ningún empate exacto —medido: 0 pares con el mismo
 * `created_at` entre los 13.412 movimientos, porque la columna guarda microsegundos, aunque
 * sí hay 68 grupos dentro de un mismo segundo—, así que esto es un seguro y no un arreglo.
 */
class MovimientosDeSaldo
{
    /** Lo que trae la pantalla al abrirse. */
    public const PRIMERA_PAGINA = 10;

    /** Y cada «Cargar más». Son los números del sistema viejo. */
    public const SIGUIENTES = 5;

    /**
     * Los movimientos desde `$desde`, y si hay más atrás.
     *
     * Un cadete que cobra por semana no tiene movimientos de saldo: la lista viene vacía,
     * como en el sistema viejo, y la pantalla de la app ni se dibuja —`*ngIf="modalidad ===
     * 'Saldo'"`—.
     *
     * @return array{movimientos: Collection<int, MovimientoCobranzaSaldo>, hay_mas: bool}
     */
    public function pagina(Cadete $cadete, int $desde = 0): array
    {
        if (! $cadete->cobraPorSaldo()) {
            /** @var Collection<int, MovimientoCobranzaSaldo> $vacia */
            $vacia = new Collection;

            return ['movimientos' => $vacia, 'hay_mas' => false];
        }

        $cuantos = $desde === 0 ? self::PRIMERA_PAGINA : self::SIGUIENTES;

        $traidos = $cadete->movimientosCobranzaSaldo()
            ->masRecientesPrimero()
            ->orderByDesc('id')
            ->offset($desde)
            ->limit($cuantos + 1)
            ->get();

        return [
            'movimientos' => $traidos->take($cuantos)->values(),
            'hay_mas' => $traidos->count() > $cuantos,
        ];
    }
}
