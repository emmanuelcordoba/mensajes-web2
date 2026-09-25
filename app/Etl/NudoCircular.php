<?php

namespace App\Etl;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El único ciclo del esquema: `users.cliente_restringido_id` apunta a
 * `clientes` y `clientes.user_id` apunta a `users`, así que no hay orden de
 * carga que satisfaga a las dos claves.
 *
 * Se rompe por el lado más chico. `users` carga con la columna en NULL,
 * después carga `clientes`, y esto vuelve con un UPDATE. Son 2 usuarios en
 * producción —cuentas de rol `restringido`, atadas a un comercio; ver PANEL-3 y
 * PANEL-6—, pero el ciclo existiría igual con uno solo.
 */
class NudoCircular
{
    public function __construct(private Origen $origen) {}

    /**
     * Devuelve cuántos usuarios quedaron con su cliente restringido.
     */
    public function cerrar(): int
    {
        $users = MapaDeIds::mapa('users');
        $clientes = MapaDeIds::mapa('clientes');

        $cerrados = 0;

        $pendientes = $this->origen->documentos(
            'users',
            ['cliente_restringido_id' => ['$nin' => [null, '']]],
            ['cliente_restringido_id'],
        );

        foreach ($pendientes as $documento) {
            $user = $users[Origen::id($documento['_id'] ?? null)] ?? null;
            $legacy = Origen::id($documento['cliente_restringido_id'] ?? null);
            $cliente = $legacy === null ? null : ($clientes[$legacy] ?? null);

            if ($user === null || $cliente === null) {
                // Dejarlo en NULL no es una opción silenciosa: una cuenta
                // `restringido` sin comercio asignado no es una cuenta más
                // limitada, es una cuenta cuyo límite no se sabe aplicar.
                throw new RuntimeException(
                    'No se pudo resolver el cliente restringido del usuario '
                    .Origen::id($documento['_id'] ?? null).": el cliente «{$legacy}» no está en migracion_ids."
                );
            }

            DB::table('users')->where('id', $user)->update(['cliente_restringido_id' => $cliente]);
            $cerrados++;
        }

        return $cerrados;
    }
}
