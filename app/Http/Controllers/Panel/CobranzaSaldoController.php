<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\CargarSaldoRequest;
use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Services\CambioDeModalidadDeCobranza;
use App\Support\Momento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cobranza por saldo: el cadete carga plata y cada pedido se la descuenta.
 *
 * Es la pantalla «Cobranzas · Saldo» del menú lateral viejo, y **es la que la empresa usa
 * de verdad**: 13.412 movimientos, el último de la semana pasada, contra 15 cadetes que
 * alguna vez tuvieron abierto un ciclo de cobranza semanal. Ver el mensaje del commit.
 *
 * ⚠️ Los montos son `NUMERIC(12,2)` y viajan como string. No se castean a float en ningún
 * lado: es la plata de una persona, y el float es donde se pierden los centavos.
 *
 * ⚠️ El porcentaje que define el saldo mínimo se edita desde acá, y es la configuración
 * que la pantalla de Configuraciones dejó afuera a propósito —«va con la cobranza por
 * saldo, que todavía no está»—. Se guarda por la misma ruta y con el mismo validador que
 * las otras ocho: una regla, dos pantallas.
 */
class CobranzaSaldoController extends Controller
{
    /** Las tres vistas del listado. La primera es la que se abre. */
    public const MODALIDADES = ['saldo', 'semanal', 'todas'];

    public function index(Request $request): Response
    {
        $this->authorize('verCobranzas', Cadete::class);

        $buscado = trim($request->string('buscar')->value());
        $modalidad = $request->string('modalidad')->value();

        if (! in_array($modalidad, self::MODALIDADES, true)) {
            $modalidad = 'saldo';
        }

        $cadetes = Cadete::query()
            ->when($modalidad === 'saldo', fn (Builder $q) => $q->where('modalidad_cobranza', Cadete::COBRANZA_SALDO))
            ->when($modalidad === 'semanal', fn (Builder $q) => $q->where('modalidad_cobranza', Cadete::COBRANZA_SEMANAL))
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('apellidos', 'ilike', '%'.$buscado.'%')
                        ->orWhere('nombres', 'ilike', '%'.$buscado.'%');

                    // Por móvil sólo si lo escrito es un número, como en el resto.
                    if (ctype_digit($buscado)) {
                        $w->orWhere('numero_movil', (int) $buscado);
                    }
                },
            ))
            ->orderBy('numero_movil')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
                'modalidad' => $cadete->modalidad_cobranza,
                'por_saldo' => $cadete->cobraPorSaldo(),
                // Como string: es plata y no pasa por float en ningún punto del camino.
                'saldo' => Pedido::comoMonto($cadete->cobranza_saldo),
                'deuda' => Pedido::comoMonto($cadete->monto_deuda),
                'cambio' => self::decision('cambiarModalidad', $cadete),
                'pasaria_a' => CambioDeModalidadDeCobranza::laOtra($cadete),
            ]);

        $porcentaje = Configuracion::query()->where('nombre', 'COBRANZA_SALDO_PORCENTAJE')->first();

        return Inertia::render('cobranzas/saldo/index', [
            'cadetes' => $cadetes,
            'filtros' => ['buscar' => $buscado, 'modalidad' => $modalidad],
            'saldo_minimo' => Cadete::saldoMinimoSiSePuede(),
            'porcentaje' => $porcentaje === null ? null : [
                'id' => $porcentaje->id,
                'titulo' => $porcentaje->titulo,
                'valor' => $porcentaje->valor,
            ],
        ]);
    }

    public function show(Cadete $cadete): Response
    {
        $this->authorize('verCobranzas', Cadete::class);

        $movimientos = $cadete->movimientosCobranzaSaldo()
            ->with(['user:id,name', 'pedido:id,numero'])
            ->masRecientesPrimero()
            ->paginate(15)
            ->through(fn (MovimientoCobranzaSaldo $movimiento): array => [
                'id' => $movimiento->id,
                'cuando' => Momento::fechaYHora($movimiento->created_at),
                'tipo' => $movimiento->tipo,
                'monto' => Pedido::comoMonto($movimiento->monto),
                'suma' => $movimiento->monto_positivo,
                // ⚠️ El saldo ANTES del movimiento, que es como lo guarda la tabla. Hay
                // 44 que lo tienen en null, y son la primera carga de 44 cadetes: el
                // porqué está en el modelo.
                'saldo_previo' => $movimiento->saldo_parcial,
                // ⚠️ Null en los 12.827 migrados: en el sistema viejo `user_id` no estaba
                // en el `fillable` y la asignación masiva lo descartaba en silencio
                // (PANEL-13). Quedan sin atribuir para siempre.
                'quien' => $movimiento->user?->name,
                // ⚠️ La columna es nueva. Sin ella no había forma de impedir ni de ver que
                // un pedido se cobrara dos veces: COB-1 midió 438 cobros dobles por
                // $233.243,64. Ahora un índice único parcial deja uno solo por pedido.
                'pedido' => $movimiento->pedido?->numero,
            ]);

        $cargar = Gate::inspect('cargarSaldo', $cadete);

        return Inertia::render('cobranzas/saldo/show', [
            'cadete' => [
                'id' => $cadete->id,
                'movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
                'modalidad' => $cadete->modalidad_cobranza,
                'saldo' => Pedido::comoMonto($cadete->cobranza_saldo),
            ],
            'movimientos' => $movimientos,
            'saldo_minimo' => Cadete::saldoMinimoSiSePuede(),
            'cargar' => [
                'permitido' => $cargar->allowed(),
                'motivo' => $cargar->allowed() ? null : $cargar->message(),
            ],
        ]);
    }

    /**
     * Carga saldo.
     *
     * La escritura vive en `Cadete::cargarSaldo()`, al lado de `cobrarSaldoPedido()`, que
     * es su gemelo: uno suma y el otro resta, los dos en una transacción.
     */
    public function cargar(CargarSaldoRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('cargarSaldo', $cadete);

        // Entero validado; `(string)` porque de acá para adentro es `numeric-string`.
        $monto = (string) $request->integer('monto');

        $cadete->cargarSaldo($monto, $request->user()?->id);

        return back()->with('success',
            'Se cargaron $'.$monto.' al móvil '.$cadete->numero_movil
            .'. Saldo: $'.Pedido::comoMonto($cadete->cobranza_saldo).'.');
    }

    /**
     * Pasa al cadete de una modalidad a la otra.
     *
     * ⚠️ Sin esto la modalidad no se podía cambiar desde ninguna pantalla: el alta la pone
     * en 'Semanal' y ahí quedaba. Es PANEL-8. La regla —no con plata en el medio— vive en
     * el servicio, y la política pregunta la misma.
     */
    public function cambiarModalidad(Cadete $cadete): RedirectResponse
    {
        $this->authorize('cambiarModalidad', $cadete);

        $modalidad = (new CambioDeModalidadDeCobranza)->cambiar($cadete, request()->user());

        return back()->with('success',
            'El móvil '.$cadete->numero_movil.' pasó a cobranza '.mb_strtolower($modalidad).'.');
    }

    /**
     * Si se puede, y si no, por qué no.
     *
     * @return array{permitido: bool, motivo: string|null}
     */
    protected static function decision(string $accion, Cadete $cadete): array
    {
        $respuesta = Gate::inspect($accion, $cadete);

        return [
            'permitido' => $respuesta->allowed(),
            'motivo' => $respuesta->allowed() ? null : $respuesta->message(),
        ];
    }
}
