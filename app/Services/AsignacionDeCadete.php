<?php

namespace App\Services;

use App\Jobs\CancelarPedidoSinConfirmar;
use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guardar un pedido de la lista de espera y, si eligieron uno, darle el cadete.
 *
 * Es el `ajax/pedidos/actualizar` del panel viejo: el modal que se abre al hacer doble clic
 * en la lista de espera edita unos campos del pedido y tiene un selector de cadete, y las
 * dos cosas viajan en el mismo botón. Por eso es un solo servicio y no dos.
 *
 * ⚠️ **El estado al que va el pedido depende de dónde está trabajando el cadete**, y eso es
 * lo que de verdad hace esta pantalla:
 *
 * - Puesto en la cola desde la central (`activo-web`): el pedido queda **Finalizado** y el
 *   cadete **inactivo**. No hay app que confirme nada; el cadete está en el mostrador, se
 *   lleva el pedido y sale de la cola. Es también donde se le cobra el saldo.
 * - Trabajando desde la app (`activo-app` o `con-pedido-app`): el pedido queda **Asignado**
 *   y empieza a correr un reloj de cinco minutos. Si no lo confirma, se cancela solo.
 * - Cualquier otro estado: **Finalizado**, como el `default` del switch viejo.
 *
 * ⚠️ **Las notificaciones push NO se portan.** El sistema viejo manda tres desde acá —al
 * cadete que le toca un pedido, al cliente cuando se le cotiza, y al cadete que perdió el
 * pedido por no confirmarlo—. No hay Firebase en el sistema nuevo y eso es una decisión
 * tomada, no un olvido: lo que falta es avisar, no decidir.
 */
class AsignacionDeCadete
{
    /**
     * Lo que el modal deja editar.
     *
     * ⚠️ Es una lista explícita y ése es el punto. En el sistema viejo esto era
     * `$pedido->update($request->all())`, o sea asignación masiva sobre un `$fillable` que
     * incluye `estado`, `plataforma_origen`, `user_id` y `cliente_id`: el modal no los
     * manda, pero un request armado a mano sí, y con eso se cambiaba el dueño de un pedido.
     */
    public const EDITABLES = [
        'direccion',
        'destino',
        'responsable',
        'telefono',
        'detalle',
        'valor',
        'retorno_origen',
        'garantia',
    ];

    /**
     * De qué pedidos se editan los campos.
     *
     * Los de la app quedan afuera a propósito: en el modal sus campos van deshabilitados y
     * su precio se maneja por la rama de cotización, que es otra cosa. Los de la API sí
     * entran, y es el arreglo de un agujero del sistema viejo: ahí la edición corría sólo
     * para `web`, así que en un pedido de la API **todo lo que el operador escribía se
     * descartaba en silencio** —se podía tipear el precio, apretar Asignar, y el pedido
     * quedaba asignado y sin valor—. Es justo el camino de respaldo del alta por API.
     */
    public const PLATAFORMAS_EDITABLES = [Pedido::PLATAFORMA_WEB, Pedido::PLATAFORMA_API];

    /** Cuánto tiene el cadete de la app para confirmar, antes de perder el pedido. */
    public const MINUTOS_PARA_CONFIRMAR = 5;

    /**
     * Por qué no se le puede dar este pedido a este cadete, o null si se puede.
     *
     * La única razón del sistema viejo es el saldo, y se mide contra **el valor que se está
     * tipeando**, no contra el guardado: es el que se va a cobrar.
     *
     * ⚠️ Dice cuánto falta. El panel viejo contestaba «Cadete con saldo insuficiente.» y el
     * operador tenía que ir a otra pantalla a ver de cuánto estamos hablando.
     *
     * ⚠️ Y si falta la configuración, lo dice en vez de dejar pasar. En el sistema viejo el
     * porcentaje ausente hacía que el costo diera 0 —así que el control no frenaba a nadie—
     * y después reventaba al cobrar. Ver la misma decisión en `Cadete::saldoMinimoSiSePuede()`.
     */
    public static function porQueNo(Cadete $cadete, ?string $valor): ?string
    {
        if (! $cadete->cobraPorSaldo()) {
            return null;
        }

        if (! Configuracion::existe('COBRANZA_SALDO_PORCENTAJE')) {
            return 'Falta configurar COBRANZA_SALDO_PORCENTAJE, y sin eso no se puede saber'
                .' cuánto descuenta este pedido.';
        }

        $costo = self::costoDeCobranza($valor);
        $saldo = Pedido::comoMonto($cadete->cobranza_saldo);

        if (bccomp($saldo, $costo, 2) >= 0) {
            return null;
        }

        return 'El móvil '.$cadete->numero_movil.' no tiene saldo suficiente: tiene $'
            .$saldo.' y este pedido le descuenta $'.$costo.'.';
    }

    /**
     * Guarda los campos y, si hay cadete, lo asigna.
     *
     * Todo en una transacción: el cobro del saldo, el estado del cadete y el del pedido son
     * una sola decisión. Si el índice que impide el cobro doble rechaza el movimiento, no
     * puede quedar un pedido finalizado sin cobrar.
     *
     * @param  array<string, mixed>  $campos
     */
    public function guardar(Pedido $pedido, ?Cadete $cadete, array $campos, ?int $userId = null): void
    {
        if ($cadete !== null) {
            $motivo = self::porQueNo($cadete, isset($campos['valor']) ? (string) $campos['valor'] : null);

            if ($motivo !== null) {
                // Llegar acá significa que la pantalla aceptó algo que la regla niega.
                throw new RuntimeException($motivo);
            }
        }

        $aConfirmar = DB::transaction(function () use ($pedido, $cadete, $campos, $userId): bool {
            // ⚠️ ANTES de tocar el cadete, y por dos motivos. Uno: con un cadete que cobra
            // por saldo, el control de arriba mira el valor recién tipeado y `cobrarSaldoPedido()`
            // cobra sobre `$pedido->valor`; aplicando primero, se controla y se cobra sobre el
            // mismo número. Dos: el orden viejo dejaba esto después, así que un pedido sin
            // precio asignado a un cadete de saldo le cobraba cero.
            $this->aplicarCampos($pedido, $campos);

            if ($cadete === null) {
                $this->cotizarSiEsDeLaApp($pedido, $campos);
                $pedido->save();
                LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB, userId: $userId);

                return false;
            }

            return $this->darleElPedido($pedido, $cadete, $userId);
        });

        if ($aConfirmar) {
            self::ponerElRelojDeConfirmacion($pedido);
        }
    }

    /**
     * Darle el pedido al cadete: a qué estado va, quién sale de la cola y a quién se le cobra.
     *
     * **Es el corazón de las dos pantallas y por eso está acá y no en cada una.** El alta de
     * Inicio y el modal de la lista de espera hacen exactamente esto, y en el sistema viejo
     * son dos copias del mismo `switch` —`PedidoController@store` y
     * `Ajax\PedidoController@actualizar`— que ya se separaron entre sí: la del modal cobra
     * el saldo y la de la API v1 no, y nadie decidió esa diferencia. Una regla, dos lectores.
     *
     * ⚠️ **Tiene que correr dentro de una transacción**, y la abre quien llama: el cobro del
     * saldo, el estado del cadete y el del pedido son una sola decisión. Si el índice que
     * impide el cobro doble rechaza el movimiento, no puede quedar un pedido finalizado sin
     * cobrar.
     *
     * ⚠️ **No encola el reloj de los cinco minutos**: eso va fuera de la transacción, porque
     * el trabajo diferido lee el pedido de la base y no puede encolarse antes de que la fila
     * esté escrita. Por eso devuelve si hace falta, y quien llama lo pone con
     * `ponerElRelojDeConfirmacion()`.
     *
     * @return bool Si el cadete tiene cinco minutos para confirmar.
     */
    public function darleElPedido(Pedido $pedido, Cadete $cadete, ?int $userId = null): bool
    {
        // ⚠️ El estado de ANTES, guardado antes de tocarlo: abajo hay que distinguir
        // al cadete que vino de la cola web del que ya estaba inactivo, y para cuando
        // se decide el cobro los dos dirían `inactivo`.
        $comoLlego = $cadete->estado;
        $deLaColaWeb = $comoLlego === Cadete::ESTADO_ACTIVO_WEB;

        $pedido->cadete()->associate($cadete);
        $enLaApp = $cadete->estaEnLaApp();
        $pedido->estado = $enLaApp ? Pedido::ESTADO_ASIGNADO : Pedido::ESTADO_FINALIZADO;

        if ($deLaColaWeb) {
            // Se lo lleva del mostrador: sale de la cola.
            $cadete->estado = Cadete::ESTADO_INACTIVO;
            $cadete->save();
        }

        $pedido->save();
        LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB, userId: $userId);

        if ($deLaColaWeb) {
            // ⚠️ SÓLO el que vino de la cola web, como el sistema viejo: el de la app
            // todavía no terminó nada, y a uno que ya estaba inactivo el switch viejo
            // le daba el pedido por su rama `default`, sin cobrar.
            //
            // ⚠️ Y después de guardar el pedido: el movimiento guarda `pedido_id`, que
            // es lo que impide el cobro doble (COB-1). `user_id` siempre (PANEL-13).
            $cadete->cobrarSaldoPedido($pedido, $userId);
        }

        return $enLaApp;
    }

    /** El reloj que cancela el pedido si el cadete de la app no lo confirma. */
    public static function ponerElRelojDeConfirmacion(Pedido $pedido): void
    {
        CancelarPedidoSinConfirmar::dispatch($pedido)
            ->delay(now()->addMinutes(self::MINUTOS_PARA_CONFIRMAR));
    }

    /**
     * El pedido de la app al que le cambian el precio queda cotizado, esperando que el
     * cliente acepte.
     *
     * @param  array<string, mixed>  $campos
     */
    protected function cotizarSiEsDeLaApp(Pedido $pedido, array $campos): void
    {
        if ($pedido->plataforma_origen !== Pedido::PLATAFORMA_APP || ! isset($campos['valor'])) {
            return;
        }

        $nuevo = Pedido::comoMonto((string) $campos['valor']);

        // ⚠️ `bccomp` y no `!=`: son columnas NUMERIC que llegan como string, y comparar
        // «100.00» con «100» tiene que dar iguales.
        if (bccomp(Pedido::comoMonto($pedido->valor), $nuevo, 2) === 0) {
            return;
        }

        $pedido->valor = $nuevo;
        $pedido->estado = Pedido::ESTADO_COTIZADO;
    }

    /**
     * Guarda sólo lo que el modal deja editar, y sólo de los pedidos que lo permiten.
     *
     * Un campo ausente se deja como estaba, no se pisa con null. Un campo vacío sí llega y
     * se aplica: borrar un teléfono es una edición legítima.
     *
     * @param  array<string, mixed>  $campos
     */
    protected function aplicarCampos(Pedido $pedido, array $campos): void
    {
        if (! in_array($pedido->plataforma_origen, self::PLATAFORMAS_EDITABLES, true)) {
            return;
        }

        foreach (self::EDITABLES as $campo) {
            if (! array_key_exists($campo, $campos)) {
                continue;
            }

            $valor = $campos[$campo];

            // ⚠️ Los campos de plata vacíos se guardan como null y no como 0 ni como '':
            // es la convención del resto del sistema, y la columna es nullable.
            if (in_array($campo, ['valor', 'garantia'], true)) {
                $pedido->{$campo} = is_numeric($valor) ? (string) $valor : null;

                continue;
            }

            $pedido->{$campo} = $valor;
        }
    }

    /** @return numeric-string */
    protected static function costoDeCobranza(?string $valor): string
    {
        $porcentaje = Configuracion::numeroCrudo('COBRANZA_SALDO_PORCENTAJE');

        return bcmul(Pedido::comoMonto($valor), bcdiv($porcentaje, '100', 8), 2);
    }
}
