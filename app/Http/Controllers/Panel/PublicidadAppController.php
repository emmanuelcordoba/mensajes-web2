<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\PublicidadApp;
use App\Models\PublicidadAppImagen;
use App\Services\ImagenesDeLaApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El carrusel de publicidades de la app de clientes. Son 21, y pocas visibles a la vez.
 *
 * ⚠️ La imagen no está en esta tabla: está en `publicidad_app_imagenes`, que guarda la
 * **ruta de un archivo** (PERF-2). En el sistema viejo el base64 vivía en el documento,
 * así que las 21 imágenes viajaban enteras en el `get-setup` de la app sin que nadie las
 * pidiera. Para mostrarlas acá hace falta una URL firmada: ver `ImagenesDeLaApp`.
 *
 * ⚠️ Un alta escribe dos filas —la publicidad y su imagen—, en una transacción, por lo
 * mismo que el alta de un cadete: una publicidad sin imagen es una tarjeta vacía en el
 * carrusel de la app.
 */
class PublicidadAppController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', PublicidadApp::class);

        $publicidades = PublicidadApp::query()
            ->with('imagen')
            ->orderByDesc('visible')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PublicidadApp $p): array => [
                'id' => $p->id,
                'url' => $p->url,
                'visible' => $p->visible,
                // Firmada y de media hora. Null cuando el archivo no está subido: el ETL
                // sube en dos tiempos, y una imagen rota no dice nada mientras que la
                // ausencia se puede mostrar.
                'imagen' => ImagenesDeLaApp::url($p->imagen?->ruta_archivo),
            ])
            ->all();

        return Inertia::render('publicidad-app/index', [
            'publicidades' => $publicidades,
            'puede_borrar' => request()->user()?->can('delete', PublicidadApp::class) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PublicidadApp::class);

        return Inertia::render('publicidad-app/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PublicidadApp::class);

        $datos = $request->validate($this->reglas(obligatoriaLaImagen: true), $this->mensajes());

        DB::transaction(function () use ($request, $datos): void {
            $publicidad = new PublicidadApp;
            $publicidad->url = $datos['url'];
            $publicidad->visible = $request->boolean('visible');
            $publicidad->save();

            PublicidadAppImagen::query()->create([
                'publicidad_id' => $publicidad->id,
                'ruta_archivo' => ImagenesDeLaApp::guardarPublicidad($request->file('imagen')),
            ]);
        });

        return to_route('panel.publicidad-app.index')->with('success', 'Publicidad creada.');
    }

    public function edit(PublicidadApp $publicidadApp): Response
    {
        $this->authorize('update', $publicidadApp);

        $publicidadApp->load('imagen');

        return Inertia::render('publicidad-app/edit', [
            'publicidad' => [
                'id' => $publicidadApp->id,
                'url' => $publicidadApp->url,
                'visible' => $publicidadApp->visible,
                'imagen' => ImagenesDeLaApp::url($publicidadApp->imagen?->ruta_archivo),
            ],
        ]);
    }

    public function update(Request $request, PublicidadApp $publicidadApp): RedirectResponse
    {
        $this->authorize('update', $publicidadApp);

        $datos = $request->validate($this->reglas(obligatoriaLaImagen: false), $this->mensajes());

        DB::transaction(function () use ($request, $datos, $publicidadApp): void {
            $publicidadApp->url = $datos['url'];
            $publicidadApp->visible = $request->boolean('visible');
            $publicidadApp->save();

            if (! $request->hasFile('imagen')) {
                return;
            }

            $ruta = ImagenesDeLaApp::guardarPublicidad($request->file('imagen'));

            // ⚠️ El archivo anterior NO se borra. La publicidad se da de baja en lógico y
            // se puede restaurar, y el resto del sistema tampoco borra archivos solo
            // —ver el docblock de Etl\Deposito—. Son 21 imágenes: lo que sobra lo
            // reclama una limpieza, no un camino de escritura.
            $publicidadApp->imagen()->updateOrCreate(
                ['publicidad_id' => $publicidadApp->id],
                ['ruta_archivo' => $ruta],
            );
        });

        return to_route('panel.publicidad-app.index')->with('success', 'Publicidad actualizada.');
    }

    /**
     * Mostrar o esconder, que es lo que se hace todo el tiempo.
     *
     * Es su propia acción y no una edición: cambiar si una publicidad se ve no debería
     * obligar a abrir un formulario. Igual que en el panel viejo.
     */
    public function cambiarVisibilidad(PublicidadApp $publicidadApp): RedirectResponse
    {
        $this->authorize('update', $publicidadApp);

        $publicidadApp->visible = ! $publicidadApp->visible;
        $publicidadApp->save();

        return back()->with(
            'success',
            $publicidadApp->visible ? 'La publicidad se muestra.' : 'La publicidad quedó oculta.',
        );
    }

    public function destroy(PublicidadApp $publicidadApp): RedirectResponse
    {
        $this->authorize('delete', $publicidadApp);

        // Baja lógica, como todo. El archivo queda donde está: se puede restaurar.
        $publicidadApp->delete();

        return to_route('panel.publicidad-app.index')->with('success', 'Publicidad dada de baja.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglas(bool $obligatoriaLaImagen): array
    {
        return [
            'url' => ['required', 'string', 'max:255', 'url'],
            'visible' => ['nullable', 'boolean'],
            'imagen' => [
                $obligatoriaLaImagen ? 'required' : 'nullable',
                'image',
                Rule::file()->types(['jpg', 'jpeg', 'png', 'webp'])->max('4mb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajes(): array
    {
        return [
            'imagen.required' => 'Elegí la imagen que va a mostrar la app.',
            'url.url' => 'Tiene que ser una dirección completa, con https:// adelante.',
        ];
    }
}
