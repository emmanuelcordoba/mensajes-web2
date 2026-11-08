{{--
    La cola, para mirarla mientras pasa algo del otro lado.

    ⚠️ Se usan los componentes de Filament y no clases de Tailwind sueltas: el panel de
    /admin carga la hoja de estilos de Filament, que está compilada con las clases que
    Filament usa. Una utilidad inventada acá puede no existir del otro lado.
--}}
<x-filament-panels::page>
    <div wire:poll.10s class="fi-section-content-ctn" style="display: grid; gap: 1.5rem;">

        @php($resumen = $this->resumen())

        {{-- Los cuatro números. El último es el que importa. --}}
        <x-filament::section
            heading="Cómo está la cola"
            description="Se refresca sola cada diez segundos."
        >
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem;">
                <div>
                    <div class="fi-section-header-description">Esperando</div>
                    <div style="font-size: 1.5rem; font-weight: 600;">{{ number_format($resumen['pendientes'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <div class="fi-section-header-description">En curso</div>
                    <div style="font-size: 1.5rem; font-weight: 600;">{{ number_format($resumen['enCurso'], 0, ',', '.') }}</div>
                </div>
                <div>
                    <div class="fi-section-header-description">Fallados</div>
                    <div style="font-size: 1.5rem; font-weight: 600;">
                        @if ($resumen['fallados'] > 0)
                            <x-filament::badge color="danger">{{ number_format($resumen['fallados'], 0, ',', '.') }}</x-filament::badge>
                        @else
                            0
                        @endif
                    </div>
                </div>
                <div>
                    <div class="fi-section-header-description">El más viejo esperando</div>
                    <div style="font-size: 1.5rem; font-weight: 600;">
                        @php($segundos = $resumen['segundosDelMasViejo'])
                        @if ($segundos === null)
                            <x-filament::badge color="success">nada vencido</x-filament::badge>
                        @elseif ($segundos < 60)
                            <x-filament::badge color="success">{{ $segundos }} s</x-filament::badge>
                        @elseif ($segundos < 300)
                            <x-filament::badge color="warning">{{ floor($segundos / 60) }} min</x-filament::badge>
                        @else
                            {{-- Más de cinco minutos vencido es un worker caído, no una cola ocupada. --}}
                            <x-filament::badge color="danger">{{ floor($segundos / 60) }} min</x-filament::badge>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Pedidos tomados que nadie va a liberar. --}}
        @php($sinReloj = $this->pedidosSinReloj())
        <x-filament::section
            heading="Pedidos sin reloj"
            description="Asignados hace más de cinco minutos y sin ningún trabajo que los libere. Se resuelven a mano, desde el panel."
            :icon="$sinReloj->isEmpty() ? null : \Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle"
            icon-color="danger"
        >
            @if ($sinReloj->isEmpty())
                <p class="fi-section-header-description">Ninguno. Todo pedido asignado tiene quién lo libere.</p>
            @else
                <table style="width: 100%; text-align: start; font-size: 0.875rem;">
                    <thead>
                        <tr class="fi-section-header-description">
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Número</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Cadete</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Asignado</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Hace</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sinReloj as $pedido)
                            <tr>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pedido->numero ?? $pedido->id }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pedido->cadete_id }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0; white-space: nowrap;">{{ $pedido->updated_at?->format('d/m/Y H:i') }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0; white-space: nowrap;">{{ $pedido->updated_at?->diffForHumans(short: true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- Los que fallaron: lo único que pide que alguien haga algo. --}}
        @php($fallados = $this->fallados())
        <x-filament::section heading="Fallados">
            @if ($fallados->isEmpty())
                <p class="fi-section-header-description">Ninguno.</p>
            @else
                <table style="width: 100%; text-align: start; font-size: 0.875rem;">
                    <thead>
                        <tr class="fi-section-header-description">
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Trabajo</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Falló</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Motivo</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fallados as $fallado)
                            <tr>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ class_basename($fallado['trabajo']) }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0; white-space: nowrap;">{{ $fallado['fallo']->format('d/m/Y H:i') }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ Str::limit($fallado['motivo'], 120) }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0; text-align: end; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 0.5rem;">
                                        <x-filament::button
                                            size="xs"
                                            wire:click="reintentar('{{ $fallado['uuid'] }}')"
                                            wire:loading.attr="disabled"
                                        >
                                            Reintentar
                                        </x-filament::button>
                                        <x-filament::button
                                            size="xs"
                                            color="gray"
                                            wire:click="olvidar('{{ $fallado['uuid'] }}')"
                                            wire:confirm="Se borra de la lista y el trabajo no se hace. ¿Seguro?"
                                            wire:loading.attr="disabled"
                                        >
                                            Borrar
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- La cola misma. Un diferido que todavía no venció está esperando a propósito. --}}
        @php($pendientes = $this->pendientes())
        <x-filament::section heading="Esperando en la cola">
            @if ($pendientes->isEmpty())
                <p class="fi-section-header-description">Vacía.</p>
            @else
                <table style="width: 100%; text-align: start; font-size: 0.875rem;">
                    <thead>
                        <tr class="fi-section-header-description">
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Trabajo</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Pedido</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Encolado</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Vence</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Intentos</th>
                            <th style="text-align: start; padding: 0.5rem 1.25rem 0.5rem 0;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendientes as $pendiente)
                            <tr>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ class_basename($pendiente['trabajo']) }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pendiente['pedidoId'] ?? '—' }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pendiente['encolado']->format('H:i:s') }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pendiente['vence']->format('H:i:s') }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">{{ $pendiente['intentos'] }}</td>
                                <td style="padding: 0.5rem 1.25rem 0.5rem 0;">
                                    @if ($pendiente['enCurso'])
                                        <x-filament::badge color="info">en curso</x-filament::badge>
                                    @elseif ($pendiente['vence']->isFuture())
                                        <x-filament::badge color="gray">diferido</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning">vencido</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
