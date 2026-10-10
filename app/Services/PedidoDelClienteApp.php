<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que un cliente le hace a sus pedidos desde la app: pedir, mirar, aceptar y cancelar.
 *
 * ⚠️ **Las dos escrituras son condicionadas, y por el mismo motivo que las del cadete**: un
 * teléfono repite. Se toca dos veces el botón, se corta la red y la app reintenta. El estado
 * esperado va en el WHERE y sólo sigue el que cambia una fila.
 *
 * ⚠️ **Y antes no miraban el estado.** Es API-3: `destroy()` borraba el pedido en
 * cualquier estado —En curso y Finalizado incluidos, o sea con el cadete en la calle— y
 * `aceptar()` ponía «Aceptado» desde cualquier estado, así que un pedido ya entregado
 * volvía atrás.
 */
class PedidoDelClienteApp
{
    /**
     * Los estados en que el cliente todavía puede cancelar: los que no tienen cadete.
     *
     * Un pedido Asignado ya está en manos de alguien, aunque todavía no lo haya confirmado:
     * desde ahí la cancelación es un llamado a la oficina, no un botón.
     *
     * @var list<string>
     */
    public const SE_PUEDE_CANCELAR = [
        Pedido::ESTADO_SIN_ASIGNAR,
        Pedido::ESTADO_COTIZADO,
        Pedido::ESTADO_ACEPTADO,
        Pedido::ESTADO_RECHAZADO,
        Pedido::ESTADO_CANCELADO,
    ];

    /**
     * Y desde dónde se puede aceptar.
     *
     * «Aceptado» está incluido a propósito: es el mismo paso repetido, y el cliente ya había
     * confirmado la cotización. Al segundo toque se le contesta lo mismo que al primero.
     *
     * @var list<string>
     */
    public const SE_PUEDE_ACEPTAR = [
        Pedido::ESTADO_COTIZADO,
        Pedido::ESTADO_ACEPTADO,
    ];

    /**
     * Los pedidos del cliente, el último movido primero.
     *
     * @return Collection<int, Pedido>
     */
    public function suyos(Cliente $cliente): Collection
    {
        return Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('updated_at')
            // Desempata dos pedidos movidos en el mismo instante, que sin esto salen en el
            // orden que la base quiera. Es lo mismo que el chat.
            ->orderByDesc('id')
            ->with('cadete.user.foto')
            ->get();
    }

    /** Si este pedido es de este cliente. */
    public static function esDe(Pedido $pedido, Cliente $cliente): bool
    {
        return $pedido->cliente_id === $cliente->id;
    }

    /**
     * El cliente acepta la cotización.
     *
     * @return bool Si esta llamada fue la que lo movió, o si ya estaba aceptado.
     */
    public function aceptar(Pedido $pedido, User $cuenta): bool
    {
        if (! in_array($pedido->estado, self::SE_PUEDE_ACEPTAR, true)) {
            return false;
        }

        $cambio = Pedido::query()
            ->whereKey($pedido->id)
            ->whereIn('estado', self::SE_PUEDE_ACEPTAR)
            ->update(['estado' => Pedido::ESTADO_ACEPTADO]);

        if ($cambio === 0) {
            return false;
        }

        $pedido->refresh();

        LogEstadoPedido::registrar($pedido, LogEstadoPedido::CLIENTE_APP, userId: $cuenta->id);

        return true;
    }

    /**
     * El cliente cancela el pedido.
     *
     * ⚠️ Es un borrado lógico, como en el sistema viejo: el pedido desaparece de su listado y
     * la fila queda. La condición va en la MISMA escritura, así que si la central le asigna
     * un cadete en ese instante, el pedido no se borra.
     *
     * @return bool Si se pudo cancelar.
     */
    public function cancelar(Pedido $pedido): bool
    {
        return Pedido::query()
            ->whereKey($pedido->id)
            ->whereIn('estado', self::SE_PUEDE_CANCELAR)
            ->delete() > 0;
    }

    /**
     * El cliente carga un pedido desde la app.
     *
     * ⚠️ **El precio lo manda el cliente**, y es el que el servidor le cotizó dando la vuelta
     * por el teléfono: la app guarda `costo` de `cotizar()` y lo devuelve en el alta. O sea
     * que una petición armada a mano crea un pedido con el precio que quiera, incluso cero.
     * El sistema viejo hace lo mismo y no se puede cerrar desde acá: el alta no manda la
     * distancia, así que no hay con qué recalcular. Cerrarlo pide que la cotización se emita
     * firmada o se guarde del lado del servidor, y eso cambia el contrato de la app.
     *
     * ⚠️ `numero` no se asigna: sale de `pedidos_numero_seq` por DEFAULT. El sistema viejo lo
     * resolvía contando documentos.
     *
     * @param  array<string, mixed>  $datos  Ya validados.
     */
    public function crear(array $datos, Cliente $cliente, User $cuenta): Pedido
    {
        return DB::transaction(function () use ($datos, $cliente, $cuenta): Pedido {
            $pedido = new Pedido;

            $pedido->direccion = self::armarDireccion(
                $datos['direccion_origen_calle_altura'],
                $datos['direccion_origen_depto'] ?? null,
                $datos['direccion_origen_localidad'],
            );

            $pedido->destino = self::armarDireccion(
                $datos['direccion_destino_calle_altura'],
                $datos['direccion_destino_depto'] ?? null,
                $datos['direccion_destino_localidad'],
            );

            $pedido->tipo_paquete = $datos['tipo_paquete'];
            $pedido->peso_paquete = $datos['peso_paquete'];
            $pedido->retorno_origen = (bool) $datos['retorno_origen'];
            $pedido->telefono = $datos['telefono'];
            $pedido->detalle = $datos['detalle'] ?? null;

            // ⚠️ Los campos de plata, como texto y con null cuando no vienen: es la
            // convención del resto del sistema y las columnas son nullable.
            $pedido->valor = Pedido::comoMonto((string) $datos['costo']);
            $pedido->garantia = self::monto($datos['envia_dinero_monto'] ?? null);
            $pedido->valor_declarado = self::monto($datos['valor_declarado'] ?? null);

            $pedido->estado = Pedido::ESTADO_ACEPTADO;
            $pedido->plataforma_origen = Pedido::PLATAFORMA_APP;
            $pedido->cliente_id = $cliente->id;
            $pedido->user_id = $cuenta->id;
            $pedido->save();

            LogEstadoPedido::registrar($pedido, LogEstadoPedido::CLIENTE_APP, userId: $cuenta->id);

            // `numero` lo escribe PostgreSQL al insertar.
            return $pedido->refresh();
        });
    }

    /**
     * «San Luis 312 3B, San Miguel de Tucumán»: calle y altura, el depto si vino, y la
     * localidad después de la coma.
     *
     * ⚠️ El sistema viejo pregunta `$request->has('direccion_origen_depto')`, y `has()` es
     * verdadero cuando la clave está aunque valga null: la app la manda siempre, así que la
     * dirección quedaba con un espacio de más antes de la coma. Acá se mira si tiene algo.
     */
    protected static function armarDireccion(string $calleYAltura, ?string $depto, string $localidad): string
    {
        $depto = trim((string) $depto);

        return trim($calleYAltura).($depto === '' ? '' : ' '.$depto).', '.trim($localidad);
    }

    /** Un monto que puede no venir. */
    protected static function monto(mixed $valor): ?string
    {
        return is_numeric($valor) ? Pedido::comoMonto((string) $valor) : null;
    }
}
