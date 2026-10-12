<?php

namespace App\Http\Controllers\Api\UserApi\V1;

use App\Exceptions\MapaNoDisponible;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UserApi\V1\CotizarViajeRequest;
use App\Http\Requests\Api\UserApi\V1\CrearPedidoPorApiRequest;
use App\Http\Resources\Api\UserApi\V1\PedidoDeLaApi;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use App\Services\CotizacionDeViaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La API de integración: un tercero cotiza un viaje y da de alta un pedido.
 *
 * ⚠️ **No tiene tráfico desde el 2021-12-02.** Son 76 pedidos en total —27 en 2020 y 49 en
 * 2021—, ninguno en casi cuatro años, y hay una sola cuenta con el rol `cliente_api`. Es
 * DEP-3. Se porta igual porque la decisión de darla de baja no es técnica: mientras exista la
 * cuenta, el endpoint tiene que estar.
 *
 * ⚠️ **Y por eso mismo no hay contrato publicado que preservar.** El vocabulario de esta API
 * se cambió entero en el sistema viejo el 2026-08-28 —`negocio`/`cliente` pasaron a
 * `origen`/`destino`—, rompiendo compatibilidad a sabiendas, justamente porque nadie la usa.
 * Acá se porta el diseño de hoy; lo que no se copia son los descuidos.
 *
 * ⚠️ **El precio NO se recibe nunca.** Las dos rutas lo calculan con el mismo
 * `CotizacionDeViaje`, así que el que se muestra al cotizar es el que queda al dar de alta. Es
 * la diferencia con el alta de la app de clientes, que acepta el `costo` del teléfono: ver
 * COT-1.
 *
 * ⚠️ **Los dos endpoints tratan la caída de Google distinto, y es a propósito.** Cotizar
 * contesta 503: lo único que hace es dar un precio, así que no hay a qué caer y conviene
 * reintentar. El alta crea el pedido igual, sin precio y en «Sin asignar», para que lo cotice
 * una persona desde el panel — que el proveedor de mapas esté caído no le hace perder el alta
 * al consumidor.
 */
class PedidoController extends Controller
{
    public function __construct(protected CotizacionDeViaje $cotizaciones) {}

    /**
     * Cotiza un viaje sin crear nada.
     *
     * Existe para que el consumidor pueda mostrar el precio antes de confirmar, igual que hace
     * la app de clientes. No escribe nada, así que se puede llamar las veces que haga falta.
     */
    public function cotizar(CotizarViajeRequest $request): JsonResponse
    {
        $datos = $request->validated();

        try {
            $cotizacion = $this->cotizaciones->para($datos);
        } catch (MapaNoDisponible $e) {
            Log::info('Cotización por API sin cotización automática', [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'motivo' => $e->getMessage(),
            ]);

            return self::error('El servicio de cotización no está disponible en este momento.', 503);
        }

        if ($cotizacion['error'] !== null) {
            return self::error($cotizacion['error'], 422);
        }

        return response()->json([
            'ok' => true,
            'costo' => Pedido::montoParaMostrar($cotizacion['costo']),
            'distancia' => $cotizacion['distancia'],
            'tiempo_de_demora' => Configuracion::valor('TIEMPO_DE_DEMORA'),
        ]);
    }

    /**
     * Da de alta un pedido: cotiza y crea en una sola llamada.
     *
     * ⚠️ **Se cotiza ANTES de crear nada**: una dirección fuera de cobertura tiene que rebotar
     * sin dejar un pedido a medias en la base.
     */
    public function store(CrearPedidoPorApiRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $cuenta = $request->user();

        if (! $cuenta instanceof User) {
            return self::error('No hay una cuenta autenticada.', 401);
        }

        $sinCotizar = ['error' => null, 'costo' => null, 'distancia' => null, 'origen' => null, 'destino' => null];
        $mapasCaidos = false;

        try {
            $cotizacion = $this->cotizaciones->para($datos);
        } catch (MapaNoDisponible $e) {
            $mapasCaidos = true;
            $cotizacion = $sinCotizar;

            Log::warning('Alta por API sin cotización automática', [
                'user_id' => $cuenta->id,
                'motivo' => $e->getMessage(),
            ]);
        }

        if (! $mapasCaidos && $cotizacion['error'] !== null) {
            return self::error($cotizacion['error'], 422);
        }

        $pedido = DB::transaction(function () use ($datos, $cotizacion, $cuenta): Pedido {
            $pedido = new Pedido;

            // ⚠️ Dos columnas con nombres heredados que no dicen lo que guardan:
            // `nombre_cliente` es de quién sale el envío y `responsable` quién lo recibe. La
            // API los llama `origen` y `destino`, que es lo que son.
            $pedido->nombre_cliente = $datos['origen'];
            $pedido->responsable = $datos['destino'];

            $pedido->direccion = CotizacionDeViaje::direccionParaGuardar($datos, 'origen', $cotizacion['origen']);
            $pedido->destino = CotizacionDeViaje::direccionParaGuardar($datos, 'destino', $cotizacion['destino']);

            $pedido->telefono = $datos['destino_telefono'] ?? null;
            $pedido->detalle = $datos['aclaracion_adicional'] ?? null;
            $pedido->gastronomia = (bool) ($datos['gastronomia'] ?? false);
            $pedido->retorno_origen = (bool) ($datos['retorno_origen'] ?? false);

            $garantia = $datos['envia_dinero_monto'] ?? null;
            $pedido->garantia = is_numeric($garantia) ? Pedido::comoMonto((string) $garantia) : null;
            $pedido->valor = $cotizacion['costo'];

            // ⚠️ Un pedido de esta API no tiene cliente: lo carga un tercero, no un comercio
            // de la base. `cliente_id` queda en null, que es el caso del 57% de los pedidos.
            $pedido->cliente_id = null;
            $pedido->user_id = $cuenta->id;

            /*
            | ⚠️ **NO va «Cotizado» acá, aunque el nombre parezca el correcto.** En este
            | sistema «Cotizado» significa «el operador le puso precio y se espera que el
            | CLIENTE lo acepte desde la app», y un pedido de esta API no tiene cliente
            | asociado: nadie podría aceptarlo nunca, así que quedaría ahí para siempre y
            | fuera de la lista de espera. Nace «Aceptado» cuando el precio salió —igual que
            | un pedido de la app de clientes, que también llega confirmado por quien lo
            | carga— y «Sin asignar» cuando no, que es donde caían todos hasta el 2026-08-28.
            | Los dos aparecen en la lista de espera, que es lo que hace falta para que
            | alguien le asigne un cadete.
            */
            $pedido->estado = $cotizacion['costo'] === null
                ? Pedido::ESTADO_SIN_ASIGNAR
                : Pedido::ESTADO_ACEPTADO;

            $pedido->plataforma_origen = Pedido::PLATAFORMA_API;
            $pedido->save();

            LogEstadoPedido::registrar($pedido, LogEstadoPedido::API, userId: $cuenta->id);

            // `numero` lo escribe PostgreSQL al insertar.
            return $pedido->refresh();
        });

        return response()->json([
            'ok' => true,
            'pedido' => new PedidoDeLaApi($pedido),
            'distancia' => $cotizacion['distancia'],
        ]);
    }

    /**
     * El formato de error de esta API.
     *
     * Es el mismo `{error, code}` que las apps, por una razón distinta: acá no hay un cliente
     * publicado que lo imponga, pero tener tres formatos de error en la misma API sería gratis
     * de evitar y caro de explicar.
     */
    protected static function error(string $mensaje, int $codigo): JsonResponse
    {
        return response()->json(['error' => $mensaje, 'code' => $codigo], $codigo);
    }
}
