{{--
    Lo que falló, contado.

    ⚠️ Se usan los componentes de Filament y no clases de Tailwind sueltas: el panel de
    /admin carga la hoja de estilos de Filament, que está compilada con las clases que
    Filament usa. Una utilidad inventada acá puede no existir del otro lado. Es lo mismo
    que dice la vista de la cola, y por la misma razón.

    ⚠️ Cada número lleva a Telescope, que es donde está el pedido entero. Esta pantalla
    decide cuál vale la pena abrir; la otra contesta qué le pasó.
--}}
<x-filament-panels::page>
    <div style="display: grid; gap: 1.5rem;">

        @unless ($this->estaPrendido())
            <x-filament::section heading="Telescope está apagado">
                <p>
                    Mientras <code>TELESCOPE_ENABLED</code> esté en falso no se guarda nada
                    nuevo, así que lo que se ve acá abajo es lo que había quedado de antes.
                </p>
            </x-filament::section>
        @endunless

        {{-- Cuántos días se miran. Uno es lo que deja ver el monitoreo de Cloud. --}}
        <x-filament::section
            heading="Fallas de los últimos {{ $this->dias }} {{ $this->dias === 1 ? 'día' : 'días' }}"
            description="Todo lo que respondió 400 o más. Lo guarda Telescope y se poda a los treinta días."
        >
            {{--
                El selector va en el cuerpo y no en el encabezado: el slot `headerEnd` de
                la sección no existe en esta versión de Filament y se dibujaba la nada.
            --}}
            <div style="margin-bottom: 1rem;">
                <x-filament::button.group>
                    @foreach ($this->diasQueSePuedenMirar() as $dias)
                        <x-filament::button
                            size="sm"
                            :color="$this->dias === $dias ? 'primary' : 'gray'"
                            wire:click="$set('dias', {{ $dias }})"
                        >
                            {{ $dias }} {{ $dias === 1 ? 'día' : 'días' }}
                        </x-filament::button>
                    @endforeach
                </x-filament::button.group>
            </div>

            @php($total = $this->total())

            <div style="font-size: 1.5rem; font-weight: 600;">
                {{ number_format($total, 0, ',', '.') }}
            </div>

            @if ($total > 0)
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;">
                    @foreach ($this->porCodigo() as $fila)
                        <a href="{{ url('/telescope/requests?tag=codigo:'.$fila['codigo']) }}" target="_blank">
                            <x-filament::badge :color="$this->colorDe($fila['codigo'])">
                                {{ $fila['codigo'] }} · {{ number_format($fila['cuantas'], 0, ',', '.') }}
                            </x-filament::badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        @if ($total > 0)
            {{-- Por día: si lo de hoy es lo normal o fue un día raro. --}}
            <x-filament::section heading="Por día">
                <table class="fi-ta-table" style="width: 100%;">
                    <tbody>
                        @foreach ($this->porDia() as $fila)
                            <tr>
                                <td style="padding: 0.25rem 0;">{{ $fila['dia'] }}</td>
                                <td style="padding: 0.25rem 0; text-align: right; font-variant-numeric: tabular-nums;">
                                    {{ number_format($fila['cuantas'], 0, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1.5rem;">
                {{-- Por ruta. --}}
                <x-filament::section heading="Por ruta">
                    <table class="fi-ta-table" style="width: 100%;">
                        <tbody>
                            @foreach ($this->porRuta() as $fila)
                                <tr>
                                    <td style="padding: 0.25rem 0; word-break: break-all;">{{ $fila['ruta'] }}</td>
                                    <td style="padding: 0.25rem 0.5rem;">
                                        <x-filament::badge :color="$this->colorDe($fila['codigo'])">
                                            {{ $fila['codigo'] }}
                                        </x-filament::badge>
                                    </td>
                                    <td style="padding: 0.25rem 0; text-align: right; font-variant-numeric: tabular-nums;">
                                        {{ number_format($fila['cuantas'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>

                {{--
                    Por móvil. Es la pregunta que motivó todo esto: si son muchos cadetes
                    con un problema cada uno, o siempre el mismo teléfono.
                --}}
                <x-filament::section
                    heading="Por móvil"
                    description="Sólo lo que falló con alguien autenticado: un 401 no tiene móvil, porque no se supo quién era."
                >
                    @forelse ($this->porMovil() as $fila)
                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                            <a href="{{ url('/telescope/requests?tag=movil:'.$fila['movil']) }}" target="_blank">
                                Móvil {{ $fila['movil'] }}
                            </a>
                            <span style="font-variant-numeric: tabular-nums;">
                                {{ number_format($fila['cuantas'], 0, ',', '.') }}
                            </span>
                        </div>
                    @empty
                        <p>Ninguna falla de alguien autenticado en estos días.</p>
                    @endforelse
                </x-filament::section>
            </div>

            {{-- Las excepciones, que son las que explican un 500. --}}
            @php($excepciones = $this->excepciones())

            @if ($excepciones->isNotEmpty())
                <x-filament::section heading="Excepciones" description="Lo que explica un 500. El enlace abre la traza entera.">
                    @foreach ($excepciones as $fila)
                        <div style="padding: 0.5rem 0;">
                            <div style="display: flex; justify-content: space-between; gap: 1rem;">
                                <a href="{{ url('/telescope/exceptions/'.$fila['uuid']) }}" target="_blank">
                                    {{ class_basename($fila['clase']) }}
                                </a>
                                <x-filament::badge color="danger">
                                    {{ number_format($fila['cuantas'], 0, ',', '.') }}
                                </x-filament::badge>
                            </div>
                            <div class="fi-section-header-description" style="word-break: break-word;">
                                {{ \Illuminate\Support\Str::limit($fila['mensaje'], 160) }}
                            </div>
                        </div>
                    @endforeach
                </x-filament::section>
            @endif

            {{-- Y las últimas, para abrir una y mirarla. --}}
            <x-filament::section heading="Las últimas">
                <table class="fi-ta-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Cuándo</th>
                            <th style="text-align: left;">Ruta</th>
                            <th style="text-align: left;">Código</th>
                            <th style="text-align: left;">Móvil</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->ultimas() as $fila)
                            <tr>
                                <td style="padding: 0.25rem 0; white-space: nowrap;">{{ $fila['cuando'] }}</td>
                                <td style="padding: 0.25rem 0.5rem; word-break: break-all;">
                                    <a href="{{ url('/telescope/requests/'.$fila['uuid']) }}" target="_blank">
                                        {{ $fila['metodo'] }} {{ $fila['ruta'] }}
                                    </a>
                                </td>
                                <td style="padding: 0.25rem 0.5rem;">
                                    <x-filament::badge :color="$this->colorDe($fila['codigo'])">
                                        {{ $fila['codigo'] }}
                                    </x-filament::badge>
                                </td>
                                <td style="padding: 0.25rem 0;">{{ $fila['movil'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @else
            <x-filament::section heading="Nada falló">
                <p>Ninguna respuesta de 400 o más en estos días.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
