<?php

use App\Http\Requests\Api\CadeteApp\V1\GuardarPerfilRequest;
use App\Http\Requests\Panel\GuardarCadeteRequest;
use App\Http\Requests\Panel\GuardarPostulacionRequest;
use App\Support\FechaDeNacimiento;
use Carbon\CarbonImmutable;

/*
| El rango de la fecha de nacimiento, y los cuatro lugares que lo leen.
|
| ⚠️ **Existe porque la base no lo sujeta**: la columna es `DATE NOT NULL` sin CHECK de
| rango y acepta el año 190 —32 cadetes con fechas imposibles, 41 personas contando las
| postulaciones, DATA-10—. Mientras eso siga así, lo único que lo frena son las pantallas.
|
| ⚠️ Y lo que estos tests cuidan es que sean **cuatro lectores de un número**. Estaba copiado
| en los cuatro. Subirlo en tres y olvidarse del que falta no da ningún error: deja una
| puerta abierta, y la encuentra el primero que entra por ahí.
*/

test('the range is one number, not four copies', function () {
    $reglas = FechaDeNacimiento::reglas();

    expect($reglas)->toContain('after_or_equal:'.FechaDeNacimiento::masVieja()->toDateString())
        ->and($reglas)->toContain('before_or_equal:'.FechaDeNacimiento::masNueva()->toDateString());
});

test('the three form requests ask the same source for it', function () {
    $delRango = FechaDeNacimiento::reglas();

    $lectores = [
        'cadetes del panel' => (new GuardarCadeteRequest)->rules(),
        'postulaciones' => (new GuardarPostulacionRequest)->rules(),
        'el perfil de la app' => (new GuardarPerfilRequest)->rules(),
    ];

    foreach ($lectores as $quien => $reglas) {
        expect($reglas['fecha_nacimiento'])
            ->toBe(['required', ...$delRango], $quien.' no usa el rango compartido');
    }
});

test('the four readers also say the same thing when they say no', function () {
    $mensajes = FechaDeNacimiento::mensajes();

    // Antes había dos redacciones del mismo límite: «El cadete tiene que tener al menos 16
    // años» en cadetes y «Tiene que tener al menos 16 años» en postulaciones.
    foreach ([(new GuardarCadeteRequest), (new GuardarPostulacionRequest), (new GuardarPerfilRequest)] as $peticion) {
        expect($peticion->messages())
            ->toHaveKey('fecha_nacimiento.after_or_equal', $mensajes['fecha_nacimiento.after_or_equal'])
            ->toHaveKey('fecha_nacimiento.before_or_equal', $mensajes['fecha_nacimiento.before_or_equal']);
    }
});

test('the ends are the ages and not something close to them', function () {
    // Contra `today()` y no contra `now()`: los dos extremos son días, y la diferencia
    // contra un instante de la tarde da 16,001 años.
    $hoy = CarbonImmutable::today();

    expect((int) FechaDeNacimiento::masNueva()->diffInYears($hoy))->toBe(FechaDeNacimiento::ANOS_MINIMOS)
        ->and((int) FechaDeNacimiento::masVieja()->diffInYears($hoy))->toBe(FechaDeNacimiento::ANOS_MAXIMOS)
        // Y son días enteros, que es lo que `after_or_equal` y `before_or_equal` comparan.
        ->and(FechaDeNacimiento::masNueva()->toTimeString())->toBe('00:00:00');
});

test('and nobody writes the two ages again by hand', function () {
    // El cuarto lector es el formulario de Filament, que no pide reglas de validación sino
    // `minDate()` y `maxDate()`, así que no entra en la comparación de arriba. Lo que sí se
    // puede comprobar —y vale para los cuatro y para el que venga— es que los dos números no
    // vuelvan a aparecer escritos a mano en ningún otro lado.
    $escritos = [];

    foreach (['app', 'database/seeders'] as $carpeta) {
        $archivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($carpeta))
        );

        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $ruta = str_replace(base_path().'/', '', $archivo->getPathname());

            if ($ruta === 'app/Support/FechaDeNacimiento.php') {
                continue;
            }

            $contenido = (string) file_get_contents($archivo->getPathname());

            foreach (['subYears(16)', 'subYears(90)'] as $aMano) {
                if (str_contains($contenido, $aMano)) {
                    $escritos[] = $ruta.' -> '.$aMano;
                }
            }
        }
    }

    expect($escritos)->toBe([], 'El rango vive en FechaDeNacimiento: '.implode(', ', $escritos));
});
