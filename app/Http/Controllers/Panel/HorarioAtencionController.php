<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\HorarioAtencion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El horario en que la empresa atiende, que la app usa para avisar cuándo se puede pedir.
 *
 * ⚠️ Es **una sola fila**, así que esta pantalla no tiene listado ni alta: muestra lo que
 * hay y lo guarda. El panel viejo la trataba como un recurso con index, create y edit —y
 * un «nuevo horario» que, si alguien lo usaba dos veces, dejaba dos filas y la aplicación
 * leía la primera—. Acá si no existe se crea al guardar, y no hay forma de tener dos.
 *
 * ⚠️ `desde` y `hasta` son TIME en el esquema nuevo y eran texto en el viejo, donde el
 * panel no validaba el formato: podía quedar cualquier cosa guardada ahí.
 */
class HorarioAtencionController extends Controller
{
    public function edit(): Response
    {
        $this->authorize('viewAny', HorarioAtencion::class);

        $horario = HorarioAtencion::actual();

        return Inertia::render('horario-atencion/edit', [
            'horario' => $horario === null ? null : [
                'desde' => substr($horario->desde, 0, 5),
                'hasta' => substr($horario->hasta, 0, 5),
                'mensaje_horario' => $horario->mensaje_horario,
                'mensaje_confirmacion' => $horario->mensaje_confirmacion,
                // Para que quien lo edita vea el efecto de lo que guardó.
                'abierto_ahora' => $horario->estaAbierto(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', HorarioAtencion::class);

        $datos = $request->validate([
            'desde' => ['required', 'date_format:H:i'],
            'hasta' => ['required', 'date_format:H:i'],
            'mensaje_horario' => ['required', 'string', 'max:255'],
            'mensaje_confirmacion' => ['required', 'string', 'max:255'],
        ], [
            'desde.date_format' => 'La hora va como HH:MM, por ejemplo 08:30.',
            'hasta.date_format' => 'La hora va como HH:MM, por ejemplo 20:00.',
        ]);

        // ⚠️ `updateOrCreate` sin condición: la fila es una y no hay con qué buscarla. Es
        // lo que impide que se pueda crear una segunda.
        $horario = HorarioAtencion::actual() ?? new HorarioAtencion;
        $horario->fill($datos);
        $horario->save();

        return back()->with('success', 'Horario guardado.');
    }
}
