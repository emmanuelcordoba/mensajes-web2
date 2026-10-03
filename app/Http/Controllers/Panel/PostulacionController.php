<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\ContratarPostulacionRequest;
use App\Http\Requests\Panel\GuardarPostulacionRequest;
use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Services\ContratacionDePostulacion;
use App\Services\DocumentosDePostulacion;
use App\Support\Momento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las postulaciones para cadete: el legajo de quien se presenta, y la decisión.
 *
 * Es la pantalla de Postulaciones del menú lateral viejo. Son 725, de las cuales 583 ya
 * fueron contratadas.
 *
 * ⚠️ **Acá viven los documentos de identidad de esas personas** —DNI de frente y de
 * dorso—, que no están en ningún otro respaldo (DATA-9). Las URL que viajan al frontend
 * están firmadas y duran media hora; la ruta del archivo no sale de acá, porque decir
 * dónde está guardado un DNI no le sirve a la pantalla y sí a cualquier otro.
 *
 * ⚠️ La decisión —contratar o rechazar— no se escribe acá. Contratar vive en
 * `ContratacionDePostulacion`, que apoya en `AltaDeCadete`: un cadete entra al sistema por
 * un solo camino, venga de una postulación o cargado a mano.
 */
class PostulacionController extends Controller
{
    /** Los tres estados del listado. El primero es el que se abre por omisión. */
    public const ESTADOS = ['pendientes', 'contratadas', 'todas'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Postulacion::class);

        $buscado = trim($request->string('buscar')->value());
        $estado = $request->string('estado')->value();

        if (! in_array($estado, self::ESTADOS, true)) {
            $estado = 'pendientes';
        }

        $postulaciones = Postulacion::query()
            ->with('cadete:id,numero_movil')
            ->when($estado === 'pendientes', fn (Builder $q) => $q->pendientes())
            ->when($estado === 'contratadas', fn (Builder $q) => $q->whereNotNull('cadete_id'))
            // ⚠️ El OR va agrupado. El listado viejo encadenaba
            // `where(nombres)->orWhere(apellidos)->doesntHave('cadete')`, y como AND liga
            // más fuerte que OR eso es `nombres LIKE x OR (apellidos LIKE y AND no
            // contratada)`: buscar por nombre devolvía también las YA CONTRATADAS, en una
            // pantalla cuyo sentido es ver las que esperan decisión.
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('nombres', 'ilike', '%'.$buscado.'%')
                        ->orWhere('apellidos', 'ilike', '%'.$buscado.'%');

                    // Por DNI sólo si lo que se escribió es un número, como en cadetes.
                    if (ctype_digit($buscado)) {
                        $w->orWhere('dni', (int) $buscado);
                    }
                },
            ))
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Postulacion $postulacion): array => [
                'id' => $postulacion->id,
                'nombre' => $postulacion->nombre_completo,
                'email' => $postulacion->email,
                'telefono' => $postulacion->telefono,
                'direccion' => $postulacion->direccion,
                'vehiculo' => Cadete::VEHICULOS[$postulacion->tipo_vehiculo] ?? $postulacion->tipo_vehiculo,
                'cuando' => Momento::fechaYHora($postulacion->created_at),
                'movil' => $postulacion->cadete?->numero_movil,
            ]);

        return Inertia::render('postulaciones/index', [
            'postulaciones' => $postulaciones,
            'filtros' => ['buscar' => $buscado, 'estado' => $estado],
            'pendientes' => Postulacion::query()->pendientes()->count(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Postulacion::class);

        return Inertia::render('postulaciones/create', [
            'vehiculos' => Cadete::VEHICULOS,
            'preguntas' => Postulacion::PREGUNTAS,
            'documentos' => PostulacionDocumento::TIPOS,
        ]);
    }

    public function store(GuardarPostulacionRequest $request): RedirectResponse
    {
        $this->authorize('create', Postulacion::class);

        // La fila y sus cuatro documentos van juntos: una postulación sin imágenes no
        // sirve para nada, y es lo que el formulario viejo podía dejar escrito cuando le
        // fallaba el JavaScript que completaba los campos ocultos.
        $postulacion = DB::transaction(function () use ($request): Postulacion {
            $postulacion = Postulacion::query()->create($request->safe()->except(
                PostulacionDocumento::TIPOS,
            ));

            foreach (PostulacionDocumento::TIPOS as $tipo) {
                DocumentosDePostulacion::guardar($postulacion, $tipo, $request->file($tipo));
            }

            return $postulacion;
        });

        return to_route('panel.cadetes.postulaciones.show', $postulacion)
            ->with('success', 'Postulación de '.$postulacion->nombre_completo.' cargada.');
    }

    public function show(Postulacion $postulacion): Response
    {
        $this->authorize('view', $postulacion);

        $postulacion->load(['documentos:id,postulacion_id,tipo,ruta_archivo', 'cadete:id,numero_movil']);

        $contratar = Gate::inspect('contratar', $postulacion);
        $rechazar = Gate::inspect('delete', $postulacion);

        return Inertia::render('postulaciones/show', [
            'postulacion' => [
                'id' => $postulacion->id,
                'nombre' => $postulacion->nombre_completo,
                'nombres' => $postulacion->nombres,
                'apellidos' => $postulacion->apellidos,
                'dni' => $postulacion->dni,
                'fecha_nacimiento' => Momento::fecha($postulacion->fecha_nacimiento),
                'direccion' => $postulacion->direccion,
                'telefono' => $postulacion->telefono,
                'email' => $postulacion->email,
                'vehiculo' => Cadete::VEHICULOS[$postulacion->tipo_vehiculo] ?? $postulacion->tipo_vehiculo,
                'recomendado_por' => $postulacion->recomendado_por,
                'cuando' => Momento::fechaYHora($postulacion->created_at),
                'movil' => $postulacion->cadete?->numero_movil,
                // ⚠️ URL firmadas por media hora, no las rutas: ver el docblock de arriba.
                'documentos' => DocumentosDePostulacion::urls($postulacion),
                // El cuestionario, que el panel viejo guardaba y después NO mostraba en
                // ningún lado: quien decidía contratar no llegaba a ver las respuestas.
                'respuestas' => $this->respuestas($postulacion),
            ],
            'identidad' => PostulacionDocumento::TIPOS_DE_IDENTIDAD,
            'etiquetas' => [
                PostulacionDocumento::TIPO_FOTO => 'Foto de perfil',
                PostulacionDocumento::TIPO_DNI_FRENTE => 'DNI, frente',
                PostulacionDocumento::TIPO_DNI_DORSO => 'DNI, dorso',
                PostulacionDocumento::TIPO_BOLETA_DE_SERVICIO => 'Boleta de servicio',
            ],
            'contratar' => [
                'permitido' => $contratar->allowed(),
                'motivo' => $contratar->allowed() ? null : $contratar->message(),
                // Sólo una propuesta: no hay secuencia y no debe haberla. Ver PANEL-5.
                'siguiente_movil' => Cadete::siguienteNumeroMovil(),
            ],
            'rechazar' => [
                'permitido' => $rechazar->allowed(),
                'motivo' => $rechazar->allowed() ? null : $rechazar->message(),
            ],
        ]);
    }

    /**
     * Contratar: la postulación se convierte en un cadete.
     *
     * ⚠️ La contraseña vuelve en el mensaje y se muestra **una sola vez**: lo que queda
     * guardado es su hash. Es lo mismo que hacía el panel viejo.
     */
    public function contratar(ContratarPostulacionRequest $request, Postulacion $postulacion): RedirectResponse
    {
        // La política niega con el motivo —ya contratada, documentos que faltan, DNI o
        // email de otro—, así que llega hasta la pantalla y no es un 403 a secas.
        $this->authorize('contratar', $postulacion);

        $password = (new ContratacionDePostulacion)->contratar(
            $postulacion,
            $request->integer('numero_movil'),
            $request->user(),
        );

        return to_route('panel.cadetes.postulaciones.index')->with('success',
            $postulacion->nombre_completo.' quedó contratado con el móvil N° '
            .$request->integer('numero_movil').'. Contraseña de ingreso: '.$password
            .' — anotala y pasásela, no se vuelve a mostrar.');
    }

    /**
     * Rechazar.
     *
     * ⚠️ Es una baja lógica. El panel viejo hacía `forceDelete()`: la postulación se
     * borraba para siempre, con los documentos de identidad de esa persona, que no están
     * en ningún otro respaldo (DATA-9). Los archivos tampoco se tocan —acá nada borra
     * archivos solo, ver el docblock de Etl\Deposito—.
     */
    public function destroy(Postulacion $postulacion): RedirectResponse
    {
        $this->authorize('delete', $postulacion);

        $nombre = $postulacion->nombre_completo;
        $postulacion->delete();

        return to_route('panel.cadetes.postulaciones.index')
            ->with('success', 'Postulación de '.$nombre.' rechazada.');
    }

    /**
     * El cuestionario, con la pregunta tal como se le hizo y la respuesta en castellano.
     *
     * @return array<int, array{pregunta: string, respuesta: string}>
     */
    protected function respuestas(Postulacion $postulacion): array
    {
        $respuestas = [];

        foreach (Postulacion::PREGUNTAS as $campo => $pregunta) {
            $respuestas[] = [
                'pregunta' => $pregunta,
                'respuesta' => match ($postulacion->{$campo}) {
                    Postulacion::SI => 'Sí',
                    Postulacion::NO => 'No',
                    default => 'Sin responder',
                },
            ];
        }

        return $respuestas;
    }
}
