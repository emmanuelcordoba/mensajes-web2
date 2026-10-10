<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las dos formas de registrarse en la app de clientes.
 *
 * **Alta nueva**: alguien que nunca pidió nada se registra y queda un cliente nuevo con su
 * cuenta. **Alta de un cliente del panel**: alguien a quien la oficina ya tenía cargado
 * —porque llamaba por teléfono— se registra contra su número de cliente, y la fila que ya
 * existe pasa a tener cuenta.
 *
 * ⚠️ **El número no se calcula en PHP.** Sale de `clientes_numero_seq` por DEFAULT y la
 * columna es UNIQUE, así que la asignación es atómica. El sistema viejo lo calcula mirando
 * toda la colección (PERF-1) y eso, en un endpoint público, es además una carrera: dos
 * registros simultáneos sacan el mismo número.
 *
 * ⚠️ **El nombre que le puso el panel se conserva.** `nombre` es del panel y `nombres` /
 * `apellidos` son de la app; `nombre_mostrado` es lo que lee el resto del sistema. Hasta
 * DATA-8 el registro reemplazaba el nombre del panel por el de la persona, y si era un
 * comercio se perdía.
 */
class RegistroDeClienteApp
{
    /** Lo más corto que puede ser un teléfono para que la oficina pueda llamar. */
    public const DIGITOS_MINIMOS_DE_TELEFONO = 7;

    /**
     * Por qué un cliente cargado desde el panel NO puede registrarse en la app, o null si
     * puede.
     *
     * ⚠️ **La preguntan los dos pasos del registro, y ahí estaba SEC-11.** Allá sólo la
     * miraba `getCliente()`, el paso que lee; `registerCliente()`, el que ESCRIBE, aceptaba
     * cualquier id: le colgaba un usuario nuevo a un cliente ya registrado, que así perdía su
     * cuenta y todos sus pedidos. Una regla, dos lectores, y hay un test que los compara.
     */
    public static function porQueNoSePuedeRegistrar(Cliente $cliente): ?string
    {
        if ($cliente->user_id !== null) {
            return 'El cliente ingresado ya está registrado.';
        }

        $telefono = trim((string) $cliente->telefono);

        if (mb_strlen($telefono) < self::DIGITOS_MINIMOS_DE_TELEFONO) {
            return 'El cliente ingresado no puede registrarse. Por favor comuníquese por teléfono.';
        }

        return null;
    }

    /**
     * El cliente con ese número, o null.
     *
     * ⚠️ En el sistema viejo esto es
     * `where('numero', $x)->orWhere('numero', (int) $x)`, porque en MongoDB el mismo número
     * convivía como texto y como entero. Acá la columna es INTEGER y la búsqueda es una.
     */
    public static function conNumero(int $numero): ?Cliente
    {
        return Cliente::query()->where('numero', $numero)->first();
    }

    /**
     * Alguien nuevo se registra: se crean la cuenta y el cliente.
     *
     * @param  array<string, mixed>  $datos  Ya validados.
     */
    public function registrarNuevo(array $datos): Cliente
    {
        return DB::transaction(function () use ($datos): Cliente {
            $cuenta = User::query()->create([
                'name' => Cliente::sinEspaciosDeMas($datos['nombres'].' '.$datos['apellidos']),
                'email' => $datos['email'],
                // El cast `hashed` del modelo la encripta.
                'password' => $datos['password'],
                'rol_id' => $this->rolDeClienteApp(),
            ]);

            // ⚠️ Sin `numero`: lo pone la secuencia. Y sin `nombre`, que es del panel.
            $cliente = Cliente::query()->create([
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'direccion' => $datos['direccion'],
                'telefono' => $datos['telefono'],
                'plataforma' => Cliente::PLATAFORMA_APP,
                'user_id' => $cuenta->id,
            ]);

            // `nombre_mostrado` y `numero` los escribe PostgreSQL al insertar.
            return $cliente->refresh();
        });
    }

    /**
     * Un cliente que el panel ya tenía cargado pasa a tener cuenta.
     *
     * @param  array<string, mixed>  $datos  Ya validados.
     */
    public function registrarExistente(Cliente $cliente, array $datos): Cliente
    {
        $motivo = self::porQueNoSePuedeRegistrar($cliente);

        if ($motivo !== null) {
            // Llegar acá significa que la validación aceptó algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        return DB::transaction(function () use ($cliente, $datos): Cliente {
            $cuenta = User::query()->create([
                'name' => Cliente::sinEspaciosDeMas($datos['nombres'].' '.$datos['apellidos']),
                'email' => $datos['email'],
                'password' => $datos['password'],
                'rol_id' => $this->rolDeClienteApp(),
            ]);

            // El nombre del panel queda donde está; esto escribe los de la persona.
            $cliente->ponerNombresDePersona($datos['nombres'], $datos['apellidos']);
            $cliente->direccion = $datos['direccion'];
            $cliente->plataforma = Cliente::PLATAFORMA_APP;
            $cliente->user_id = $cuenta->id;
            $cliente->save();

            return $cliente->refresh();
        });
    }

    /** El rol con el que entra la app de clientes. */
    protected function rolDeClienteApp(): int
    {
        $rol = Rol::query()->where('rol', Rol::CLIENTE_APP)->first();

        if ($rol === null) {
            throw new RuntimeException('Falta el rol '.Rol::CLIENTE_APP.', y sin eso no hay cuenta que crear.');
        }

        return $rol->id;
    }
}
