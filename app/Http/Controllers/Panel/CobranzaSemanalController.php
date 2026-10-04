<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\AbrirCicloRequest;
use App\Http\Requests\Panel\RegistrarPagoRequest;
use App\Models\Cadete;
use App\Models\MontoSemanal;
use App\Models\Pedido;
use App\Services\CobranzaSemanal;
use App\Support\Momento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cobranza semanal: el cadete paga una cuota por semana.
 *
 * ⚠️ **ESTÁ MUERTA, Y SE PORTÓ IGUAL.** 521 cadetes abrieron un ciclo alguna vez, **10
 * registraron un pago**, y el último fue el 2022-04-09. Los 15 ciclos vigentes no tienen
 * un solo pago, y sus fechas son imposibles —vencimientos en 2027, 2029 y 3030—. La
 * empresa cobra por saldo. Se porta porque la pantalla está en el menú viejo y sacarla no
 * es una decisión de quien porta.
 *
 * ⚠️ **La aritmética de «reconocer semana» está mal y se dejó como está**: ver PANEL-21 y
 * el docblock de `App\Services\CobranzaSemanal`. Hay un test que congela el resultado.
 *
 * ⚠️ Los montos son `NUMERIC(12,2)` y viajan como string. Las cuentas van con `bcmath`.
 */
class CobranzaSemanalController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('verCobranzas', Cadete::class);

        $buscado = trim($request->string('buscar')->value());

        $cadetes = Cadete::query()
            ->where('modalidad_cobranza', Cadete::COBRANZA_SEMANAL)
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('apellidos', 'ilike', '%'.$buscado.'%')
                        ->orWhere('nombres', 'ilike', '%'.$buscado.'%');

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
                'vehiculo' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
                'tiene_ciclo' => $cadete->fecha_inicio !== null,
                'vencimiento' => Momento::fecha($cadete->fecha_vencimiento),
                'vencido' => $cadete->fecha_vencimiento !== null
                    && $cadete->fecha_vencimiento->lt(today()),
                'cuota' => Pedido::comoMonto($cadete->monto_semanal),
                'deuda' => Pedido::comoMonto($cadete->monto_deuda),
            ]);

        // ⚠️ Sobre TODOS los cadetes, como el listado viejo —que sumaba `Cadete::sum()`
        // sin filtrar por modalidad—. Incluye por lo tanto a los de saldo, cuyas columnas
        // semanales quedaron con lo que tuvieran. Se deja igual: cambiar el total es
        // cambiar un número que la oficina mira, y eso se decide, no se arregla al pasar.
        $totales = DB::table('cadetes')
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(monto_deuda), 0) AS deuda')
            ->selectRaw('COALESCE(SUM(monto_pagado_efectivo), 0) AS efectivo')
            ->selectRaw('COALESCE(SUM(monto_pagado_tickets), 0) AS tickets')
            ->first();

        return Inertia::render('cobranzas/semanal/index', [
            'cadetes' => $cadetes,
            'filtros' => ['buscar' => $buscado],
            'totales' => [
                'deuda' => (string) ($totales->deuda ?? '0'),
                'efectivo' => (string) ($totales->efectivo ?? '0'),
                'tickets' => (string) ($totales->tickets ?? '0'),
            ],
            'montos' => MontoSemanal::query()
                ->orderBy('tipo_vehiculo')
                ->get(['id', 'tipo_vehiculo', 'monto'])
                ->map(fn (MontoSemanal $monto): array => [
                    'tipo_vehiculo' => $monto->tipo_vehiculo,
                    'vehiculo' => Cadete::VEHICULOS[$monto->tipo_vehiculo] ?? $monto->tipo_vehiculo,
                    'monto' => Pedido::comoMonto($monto->monto),
                ])
                ->all(),
        ]);
    }

    /**
     * La cobranza de un cadete.
     *
     * Sin ciclo abierto no hay nada que mostrar: se va derecho a abrirlo, como hacía el
     * `cobranza()` viejo.
     */
    public function show(Cadete $cadete): Response|RedirectResponse
    {
        $this->authorize('verCobranzas', Cadete::class);

        if ($cadete->fecha_inicio === null) {
            return to_route('panel.cadetes.cobranzas.semanal.ciclo', $cadete);
        }

        return Inertia::render('cobranzas/semanal/show', [
            'cadete' => $this->datosDelCadete($cadete),
        ]);
    }

    /**
     * El formulario que abre un ciclo: el primero, o el siguiente.
     *
     * ⚠️ Es una sola pantalla para lo que en el panel viejo eran dos vistas casi idénticas
     * —`new` y `reconocersemana`— que mandaban al mismo método. La diferencia era con qué
     * valores venían llenas, y eso se decide acá con una línea.
     */
    public function ciclo(Cadete $cadete): Response
    {
        $this->authorize('verCobranzas', Cadete::class);

        $esElPrimero = $cadete->fecha_inicio === null;

        return Inertia::render('cobranzas/semanal/ciclo', [
            'cadete' => $this->datosDelCadete($cadete),
            'es_el_primero' => $esElPrimero,
            'propuesta' => [
                'fecha_inicio' => today()->toDateString(),
                'fecha_vencimiento' => today()->addDays(CobranzaSemanal::DIAS_DEL_CICLO)->toDateString(),
                'monto_semanal' => CobranzaSemanal::cuotaDe($cadete),
                // ⚠️ Al reconocer una semana el formulario viejo venía con los pagos
                // ACUMULADOS, y de ahí sale el defecto de PANEL-21. Se deja igual.
                'monto_pagado_efectivo' => $esElPrimero ? '0' : Pedido::comoMonto($cadete->monto_pagado_efectivo),
                'monto_pagado_tickets' => $esElPrimero ? '0' : Pedido::comoMonto($cadete->monto_pagado_tickets),
            ],
        ]);
    }

    public function abrirCiclo(AbrirCicloRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('cobrar', $cadete);

        (new CobranzaSemanal)->abrirCiclo(
            $cadete,
            $request->date('fecha_inicio') ?? today(),
            $request->date('fecha_vencimiento') ?? today()->addDays(CobranzaSemanal::DIAS_DEL_CICLO),
            (string) $request->string('monto_semanal'),
            (string) $request->string('monto_pagado_efectivo'),
            (string) $request->string('monto_pagado_tickets'),
        );

        return to_route('panel.cadetes.cobranzas.semanal.show', $cadete)
            ->with('success', 'Ciclo abierto. Deuda: $'.Pedido::comoMonto($cadete->monto_deuda).'.');
    }

    public function registrarPago(RegistrarPagoRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('cobrar', $cadete);

        (new CobranzaSemanal)->registrarPago(
            $cadete,
            $request->date('fecha_vencimiento') ?? today(),
            (string) $request->string('monto_efectivo'),
            (string) $request->string('monto_tickets'),
        );

        return back()->with('success',
            'Pago registrado. Deuda: $'.Pedido::comoMonto($cadete->monto_deuda).'.');
    }

    /** Un día más de plazo, y queda desbloqueado. */
    public function extender(Cadete $cadete): RedirectResponse
    {
        $this->authorize('cobrar', $cadete);

        if ($cadete->fecha_vencimiento === null) {
            return back()->with('danger', 'El móvil '.$cadete->numero_movil.' no tiene un ciclo abierto.');
        }

        (new CobranzaSemanal)->extenderUnDia($cadete);

        return back()->with('success',
            'Vencimiento extendido hasta el '.Momento::fecha($cadete->fecha_vencimiento).'.');
    }

    /**
     * Las dos cuotas generales, una por vehículo.
     *
     * Son dos filas y `tipo_vehiculo` es UNIQUE, así que no hay alta ni baja: se editan.
     */
    public function guardarMontos(Request $request): RedirectResponse
    {
        $this->authorize('cobrar', Cadete::class);

        $validados = $request->validate([
            'montos' => ['required', 'array', 'size:'.count(Cadete::VEHICULOS)],
            'montos.*.tipo_vehiculo' => ['required', Rule::in(array_keys(Cadete::VEHICULOS))],
            'montos.*.monto' => ['required', 'numeric', 'min:1'],
        ]);

        DB::transaction(function () use ($validados): void {
            foreach ($validados['montos'] as $fila) {
                MontoSemanal::query()
                    ->where('tipo_vehiculo', $fila['tipo_vehiculo'])
                    ->update(['monto' => $fila['monto']]);
            }
        });

        return back()->with('success', 'Cuotas semanales actualizadas.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function datosDelCadete(Cadete $cadete): array
    {
        return [
            'id' => $cadete->id,
            'movil' => $cadete->numero_movil,
            'nombre' => $cadete->nombre_completo,
            'vehiculo' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
            'cuota_propia' => $cadete->tiene_monto_semanal_personal,
            'fecha_inicio' => Momento::fecha($cadete->fecha_inicio),
            'fecha_vencimiento' => $cadete->fecha_vencimiento?->toDateString(),
            'vencimiento' => Momento::fecha($cadete->fecha_vencimiento),
            'vencido' => $cadete->fecha_vencimiento !== null && $cadete->fecha_vencimiento->lt(today()),
            'cuota' => Pedido::comoMonto($cadete->monto_semanal),
            'efectivo' => Pedido::comoMonto($cadete->monto_pagado_efectivo),
            'tickets' => Pedido::comoMonto($cadete->monto_pagado_tickets),
            'deuda' => Pedido::comoMonto($cadete->monto_deuda),
        ];
    }
}
