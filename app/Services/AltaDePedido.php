<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cargar un pedido desde el panel: la columna del medio de Inicio.
 *
 * Es el `PedidoController@store` del sistema viejo. Lo que hace de verdad son tres cosas,
 * y las tres tienen su porqué:
 *
 *  1. **Resolver de quién es el pedido.** El campo «Cliente» puede venir elegido de la lista
 *     —y entonces hay `cliente_id`—, escrito a mano, o no venir: un pedido sin comercio es
 *     legítimo, se guarda el nombre suelto en `nombre_cliente`.
 *  2. **Pegar el departamento dentro de la dirección.** No hay columna para «Depto. / Oficina»:
 *     el dato entra en el texto de la dirección, que es lo que el cadete lee.
 *  3. **Decidir el estado según dónde está trabajando el cadete**, que es lo mismo que hace el
 *     modal de la lista de espera y por eso no está escrito dos veces: vive en
 *     `AsignacionDeCadete::darleElPedido()`.
 *
 * ⚠️ **Quién lo cargó se guarda siempre.** Es de donde sale el distintivo de dos letras que
 * la oficina usa para ver cuáles son sus pedidos, y es el 100% de los 43.318 pedidos web de
 * los últimos 180 días en producción. Ver `User::distintivo()`.
 *
 * ⚠️ **El aviso al teléfono del cadete no se manda desde acá**, y no falta: lo manda
 * `AsignacionDeCadete::darleElPedido()`, que es el método que esta pantalla y el modal de la
 * lista de espera comparten. En el sistema viejo ese aviso está escrito tres veces.
 */
class AltaDePedido
{
    /**
     * Los campos que el formulario escribe tal cual.
     *
     * ⚠️ Lista explícita, y el sistema viejo hacía `new Pedido($request->all())` sobre un
     * `$fillable` que incluye `estado`, `plataforma_origen`, `user_id` y `cliente_id`. El
     * formulario no los manda, pero un request armado a mano sí: con eso se podía crear un
     * pedido ya finalizado, firmado por otra persona y a nombre de otro comercio. Es el
     * mismo agujero que tenía la edición del modal.
     */
    public const DIRECTOS = [
        'responsable',
        'telefono',
        'detalle',
        'valor',
        'garantia',
    ];

    /**
     * Carga el pedido y, si eligieron uno, se lo da al cadete.
     *
     * @param  array<string, mixed>  $campos  Lo ya validado por `GuardarPedidoRequest`.
     */
    public function guardar(array $campos, ?Cadete $cadete, User $quienLoCarga): Pedido
    {
        if ($cadete !== null) {
            // La misma función que la pantalla consulta para no ofrecer lo que va a fallar.
            $motivo = AsignacionDeCadete::porQueNo(
                $cadete,
                isset($campos['valor']) ? (string) $campos['valor'] : null,
            );

            if ($motivo !== null) {
                // Llegar acá significa que la pantalla aceptó algo que la regla niega.
                throw new RuntimeException($motivo);
            }
        }

        [$pedido, $aConfirmar] = DB::transaction(
            function () use ($campos, $cadete, $quienLoCarga): array {
                $pedido = new Pedido;

                foreach (self::DIRECTOS as $campo) {
                    if (! array_key_exists($campo, $campos)) {
                        continue;
                    }

                    $valor = $campos[$campo];

                    // ⚠️ Los campos de plata vacíos se guardan como null y no como 0 ni como
                    // '': es la convención del resto del sistema y la columna es nullable.
                    $pedido->{$campo} = in_array($campo, ['valor', 'garantia'], true)
                        ? (is_numeric($valor) ? (string) $valor : null)
                        : $valor;
                }

                $pedido->direccion = self::conElDepartamento(
                    $campos['direccion'] ?? null,
                    $campos['direccion_detalle'] ?? null,
                );
                $pedido->destino = self::conElDepartamento(
                    $campos['destino'] ?? null,
                    $campos['destino_detalle'] ?? null,
                );

                $pedido->retorno_origen = (bool) ($campos['retorno_origen'] ?? false);

                $this->ponerleElCliente($pedido, $campos);

                $pedido->user()->associate($quienLoCarga);
                $pedido->plataforma_origen = Pedido::PLATAFORMA_WEB;

                if ($cadete === null) {
                    $pedido->estado = Pedido::ESTADO_SIN_ASIGNAR;
                    $pedido->save();
                    LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB, userId: $quienLoCarga->id);

                    return [$pedido, false];
                }

                // El estado al que va, quién sale de la cola y a quién se le cobra: la misma
                // regla que el modal de la lista de espera.
                $aConfirmar = (new AsignacionDeCadete)->darleElPedido($pedido, $cadete, $quienLoCarga->id);

                return [$pedido, $aConfirmar];
            }
        );

        if ($aConfirmar) {
            AsignacionDeCadete::ponerElRelojDeConfirmacion($pedido);
        }

        return $pedido;
    }

    /**
     * De quién es el pedido.
     *
     * Tres caminos, los tres del sistema viejo: elegido de la lista, escrito a mano y
     * reconocido por el nombre, o escrito a mano y sin reconocer.
     *
     * ⚠️ **Por nombre sólo si es inequívoco.** `Cliente::unicoConNombre()` devuelve null
     * cuando hay dos comercios con el mismo nombre; el sistema viejo tenía un `getByNombre`
     * con `first()` que le colgaba el pedido al primero que devolviera la base. Ver DATA-8.
     *
     * @param  array<string, mixed>  $campos
     */
    protected function ponerleElCliente(Pedido $pedido, array $campos): void
    {
        $escrito = isset($campos['cliente']) ? (string) $campos['cliente'] : null;
        $escrito = $escrito === '' ? null : $escrito;

        if (isset($campos['cliente_id'])) {
            $cliente = Cliente::query()->find($campos['cliente_id']);

            if ($cliente !== null) {
                $pedido->cliente()->associate($cliente);

                return;
            }
        }

        $reconocido = Cliente::unicoConNombre($escrito);

        if ($reconocido !== null) {
            $pedido->cliente()->associate($reconocido);

            return;
        }

        // Un pedido para alguien que no está en el padrón: el nombre se guarda suelto.
        $pedido->nombre_cliente = $escrito;
    }

    /**
     * La dirección con el departamento adentro.
     *
     * No hay columna para «Depto. / Oficina»: el dato va en el texto, después de la calle y
     * la altura, que es donde lo lee el cadete. Una dirección de Google viene como
     * «Calle 100, San Miguel de Tucumán, Tucumán», y el departamento tiene que quedar
     * pegado a la calle, no al final.
     *
     * ⚠️ **Acá hay un defecto del sistema viejo que no se replica.** Allá esto es
     * `explode(', ', $direccion)` y después `"{$partes[0]} {$detalle}, {$partes[1]}"`: con
     * dos partes anda, pero con tres —que es justo el formato que escribe Google— **la
     * tercera se pierde**. O sea que poner un departamento en una dirección elegida del
     * autocompletado le borraba la provincia. Acá se reconstruyen todas las partes.
     */
    protected static function conElDepartamento(?string $direccion, ?string $departamento): ?string
    {
        if ($direccion === null || $direccion === '') {
            return null;
        }

        if ($departamento === null || trim($departamento) === '') {
            return $direccion;
        }

        $partes = explode(', ', $direccion);
        $partes[0] = $partes[0].' '.trim($departamento);

        return implode(', ', $partes);
    }
}
