<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Dar de baja un cliente, con lo que cuelga de él.
 *
 * Es la regla del `destroy` del panel viejo, traída entera. Dar de baja un cliente no es
 * borrar una fila: se lleva puestos sus pedidos y, si vino de la app, su cuenta.
 *
 * ⚠️ EL ORDEN ES DELIBERADO: pedidos, después la cuenta, y el cliente al final. Si algo
 * falla a mitad de camino, el cliente sigue visible en el listado apuntando a lo que
 * quedó y se puede reintentar; al revés quedaría huérfano e invisible. En el sistema
 * viejo eso importaba porque MongoDB corría standalone y no había transacción con la que
 * envolverlo. Acá sí la hay —PostgreSQL— pero el orden se conserva: un cliente puede
 * arrastrar casi 20.000 pedidos, y si esa transacción se corta por tiempo el orden sigue
 * siendo el que deja las cosas recuperables.
 *
 * ⚠️ La baja es LÓGICA en los tres. Importa: `pedidos.cliente_id` y
 * `users.cliente_restringido_id` apuntan a `clientes` con NO ACTION, así que un borrado
 * de verdad es un error de clave foránea.
 *
 * `porQueNo()` existe para que la pregunta se haga en los dos lados: el listado la hace
 * antes de dibujar el botón y el servicio antes de escribir. Es la forma que ya tienen
 * `FusionDeUsuarios` y `BorradoDeCuentaDuplicada` en el sistema viejo, y la razón es la
 * misma: el que se desincroniza en silencio es el listado, que ofrece algo que después
 * falla.
 */
class BajaDeCliente
{
    /**
     * Por qué no se puede dar de baja, o null si se puede.
     *
     * ⚠️ El único motivo es PANEL-6: una cuenta de panel con rol `restringido` puede
     * tener este comercio asignado, y no es la cuenta del cliente sino otra distinta. Si
     * el cliente se va por debajo, esa cuenta queda sin comercio: entra al panel, ve los
     * campos vacíos y en sólo lectura, y nada le explica por qué.
     */
    public static function porQueNo(Cliente $cliente): ?string
    {
        $cuentas = User::query()
            ->where('cliente_restringido_id', $cliente->id)
            ->pluck('name');

        if ($cuentas->isEmpty()) {
            return null;
        }

        return 'Está asignado a la cuenta de panel «'.$cuentas->implode('», «')
            .'». Reasigná esa cuenta o dala de baja primero.';
    }

    /**
     * Qué se va a llevar puesto, para poder decirlo antes de hacerlo.
     *
     * @return array{pedidos: int, cuenta: string|null}
     */
    public static function queArrastra(Cliente $cliente): array
    {
        return [
            'pedidos' => Pedido::query()->where('cliente_id', $cliente->id)->count(),
            'cuenta' => $cliente->user?->email,
        ];
    }

    /**
     * La baja. Devuelve lo que se llevó.
     *
     * @return array{pedidos: int, cuenta: string|null}
     */
    public function dar(Cliente $cliente, ?User $autor = null): array
    {
        $motivo = self::porQueNo($cliente);

        if ($motivo !== null) {
            // Llegar acá significa que el listado ofreció algo que la regla niega.
            throw new \RuntimeException($motivo);
        }

        // ⚠️ Masivo y no un foreach: el cliente más grande tiene casi 20.000 pedidos y
        // recorrerlos como modelos no termina nunca. Sobre un modelo con SoftDeletes,
        // `delete()` en el query builder es un update masivo de `deleted_at`, que es
        // exactamente lo que se busca.
        $pedidos = Pedido::query()->where('cliente_id', $cliente->id)->delete();

        // Sólo los que vinieron de la app tienen cuenta; los cargados desde el panel no.
        // Al quedar dada de baja, deja de poder entrar aunque conserve un token vigente:
        // el proveedor de Eloquent no devuelve registros borrados.
        $cuenta = $cliente->user?->email;
        $cliente->user?->delete();

        $cliente->delete();

        Log::info('Cliente dado de baja desde /admin', [
            'cliente_id' => $cliente->id,
            'numero' => $cliente->numero,
            'pedidos' => $pedidos,
            'cuenta' => $cuenta,
            'autor' => $autor?->email,
        ]);

        return ['pedidos' => $pedidos, 'cuenta' => $cuenta];
    }
}
