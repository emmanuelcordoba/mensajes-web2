<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El perfil que el cliente edita desde la app: lo que se escribe y dónde.
 *
 * Son dos filas —el cliente y su cuenta— en una transacción, por lo mismo que en el resto del
 * sistema: un email que choca contra el índice no puede dejar los datos del cliente escritos
 * y la cuenta sin tocar.
 *
 * ⚠️ **`users.name` se rearma, y el sistema viejo no lo hacía**: quedó distinto en 95
 * clientes. Ver DATA-8. Es el nombre con el que el panel ve la cuenta.
 *
 * ⚠️ **El `nombre` que escribió el panel no se toca.** `nombre` es del panel, `nombres` y
 * `apellidos` son de la app, y `nombre_mostrado` es lo que lee el resto del sistema. Si el
 * cliente es un comercio, su nombre comercial sigue siendo el del panel.
 */
class PerfilDelCliente
{
    /**
     * Guarda el perfil y devuelve el cliente al día.
     *
     * @param  array<string, mixed>  $datos  Ya validados por `GuardarPerfilDelClienteRequest`.
     */
    public function guardar(User $cuenta, Cliente $cliente, array $datos): Cliente
    {
        DB::transaction(function () use ($cuenta, $cliente, $datos): void {
            $cliente->ponerNombresDePersona($datos['nombres'], $datos['apellidos']);
            $cliente->direccion = $datos['direccion'];
            $cliente->telefono = $datos['telefono'];
            $cliente->save();

            $cuenta->email = $datos['email'];
            $cuenta->name = $cliente->nombreDePersona();
            $cuenta->save();
        });

        // `nombre_mostrado` lo recalcula PostgreSQL al escribir, y Eloquent no lo vuelve a
        // leer solo.
        return $cliente->refresh();
    }
}
