<?php

use App\Models\Rol;

/*
| La landing pública, en la raíz. Es la del sistema viejo, portada como página
| aislada: `Route::view`, sin Inertia, sin React y sin `@vite`.
|
| ⚠️ El test que importa es el último. De los 98 archivos de `public/landing` del
| sistema viejo se trajeron los 64 que el HTML y el CSS piden de verdad; los otros
| 34 son restos de la plantilla de Astro. Esa decisión es segura sólo mientras algo
| compruebe que no falta ninguno, y eso es lo que hace `todos los archivos que pide
| están`. Si alguien vuelve a exportar la landing y la nueva versión usa una imagen
| que no se copió, el test lo dice antes que un hueco en la página.
*/

test('the landing is what the root serves', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Mensajes - Cadeteria', escape: false)
        ->assertSee('/landing/index.dUoeYG_d.css', escape: false);
});

test('it is a page on its own, not the application', function () {
    $html = $this->get(route('home'))->getContent();

    // Inertia monta la aplicación desde un `data-page` en el div raíz; si eso
    // aparece acá, la landing dejó de ser aislada.
    expect($html)->not->toContain('data-page=')
        ->and($html)->not->toContain('@vite')
        // Y trae su propio documento: no extiende el layout de la aplicación.
        ->and($html)->toContain('<!DOCTYPE html>');
});

test('somebody already signed in still gets the landing', function () {
    // Como en el sistema viejo: la raíz es la landing para todos. Quien entra va a
    // /panel por Fortify, no por un redirect de acá.
    $this->actingAs(usuarioCon(Rol::ADMIN));

    $this->get(route('home'))->assertOk()->assertSee('Mensajes - Cadeteria', escape: false);
});

test('the favicon points at a file that exists, which in production it did not', function () {
    // Producción pide `..public/landing/images/pagina/isotipoZenithBlanco.png`: una
    // ruta rota, y un archivo que tampoco está en el repositorio.
    $this->get(route('home'))
        ->assertSee('/landing/images/pagina/favicon.svg', escape: false)
        ->assertDontSee('isotipoZenithBlanco', escape: false);

    expect(public_path('landing/images/pagina/favicon.svg'))->toBeFile();
});

test('it says it is in Spanish', function () {
    $this->get(route('home'))->assertSee('<html lang="es"', escape: false);
});

test('every file it asks for is there', function () {
    $vista = resource_path('views/landing.blade.php');
    $fuentes = ['la vista' => file_get_contents($vista)];

    foreach (glob(public_path('landing/*.css')) as $css) {
        $fuentes['el CSS '.basename($css)] = file_get_contents($css);
    }

    $pedidas = [];

    foreach ($fuentes as $quien => $texto) {
        preg_match_all('#[\'"(\s]\.{0,2}/?(?:public/)?landing/([^\'")\s>]+)#', $texto, $coincidencias);

        foreach ($coincidencias[1] as $ruta) {
            $pedidas[strtok(strtok($ruta, '?'), '#')] = $quien;
        }

        if ($quien === 'la vista') {
            continue;
        }

        // El CSS vive en /landing/, así que sus `url(...)` relativos resuelven ahí.
        preg_match_all('#url\(\s*[\'"]?\.?/?([^\'")]+?)[\'"]?\s*\)#', $texto, $coincidencias);

        foreach ($coincidencias[1] as $ruta) {
            if (str_starts_with($ruta, 'http') || str_starts_with($ruta, 'data:')) {
                continue;
            }

            $pedidas[str_replace('landing/', '', strtok(strtok($ruta, '?'), '#'))] = $quien;
        }
    }

    expect($pedidas)->not->toBeEmpty('el análisis no encontró ninguna referencia: algo cambió de forma');

    $faltan = [];

    foreach ($pedidas as $ruta => $quien) {
        if (! is_file(public_path('landing/'.$ruta))) {
            $faltan[] = $ruta.' (la pide '.$quien.')';
        }
    }

    expect($faltan)->toBe([], 'la landing pide archivos que no están: '.implode(', ', $faltan));
});
