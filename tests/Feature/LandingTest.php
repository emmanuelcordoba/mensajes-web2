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

test('its scripts only look for elements that are on the page', function () {
    // ⚠️ ES LA GENERALIZACIÓN DE UN BUG QUE ESTUVO EN PRODUCCIÓN DESDE SIEMPRE. Un
    // script escribía «Tu Paquete en Manos Seguras» dentro de #typing-container, que
    // no existe: el título nunca se vio y cada visita tiraba 28 errores de consola.
    // No lo delató nadie porque la página «se veía bien». Esto lo delata.
    $html = file_get_contents(resource_path('views/landing.blade.php'));

    preg_match_all('/getElementById\(["\']([^"\']+)["\']\)/', $html, $porId);
    preg_match_all('/querySelector(?:All)?\(["\']#([^"\'\s,)]+)["\']\)/', $html, $porSelector);

    $buscados = array_unique(array_merge($porId[1], $porSelector[1]));

    preg_match_all('/\bid=["\']([^"\']+)["\']/', $html, $definidos);

    $faltan = array_values(array_diff($buscados, $definidos[1]));
    sort($faltan);

    // Ya no falta ninguno. Los tres que faltaban —#navlinks, #hamburger y #navLayer—
    // eran de una SEGUNDA implementación del menú móvil, en JS a secas, que no
    // correspondía a este marcado: el menú lo maneja Alpine. Ese bloque se borró.
    expect($faltan)->toBe([],
        'el JS de la landing busca elementos que no están: '.implode(', ', $faltan));
});

test('nothing executable is left of the typing script', function () {
    $html = file_get_contents(resource_path('views/landing.blade.php'));

    // La cadena «Tu Paquete en Manos Seguras» SÍ sigue en el archivo, y está bien que
    // siga: quedó en el comentario que cuenta qué hacía ese código y por qué se fue.
    // Lo que no puede volver es la búsqueda del elemento.
    expect($html)->not->toContain('getElementById("typing-container")')
        ->and($html)->not->toContain('window.onload = m');
});

test('it does not borrow anything from a CDN to work', function () {
    // ⚠️ CARGABA ALPINE DOS VECES, 2.x de un CDN y 3.x de otro: dos versiones del mismo
    // framework en la misma página. Alpine es lo que abre el menú en el teléfono y lo que
    // duplica la tira de logos, así que de eso depende que la página funcione. Ahora sale
    // de /landing/, con la versión fija en el nombre.
    //
    // Las fuentes de Google que quedan en el <head> son otra cosa: si no cargan, la
    // página se ve con otra tipografía y nada más.
    $html = file_get_contents(resource_path('views/landing.blade.php'));

    preg_match_all('/<script[^>]*src=["\'](https?:)?\/\/[^"\']+["\']/', $html, $externos);

    expect($externos[0])->toBe([], 'la landing pide scripts afuera: '.implode(', ', $externos[0]));

    // Y el que la hace andar está donde tiene que estar.
    expect($html)->toContain('/landing/alpine-3.17.5.min.js')
        ->and(public_path('landing/alpine-3.17.5.min.js'))->toBeFile();
});

test('alpine is the one that runs the menu, and it is still wired to it', function () {
    // El marcado del menú es de Alpine: `x-data` con el estado, `@click` en el botón,
    // `:class` en el <nav> y `x-show` en los dos iconos. Si alguien saca una de las
    // cuatro, el botón vuelve a no hacer nada —que es como estaba— sin romper nada más,
    // o sea sin que ningún otro test se entere.
    $html = file_get_contents(resource_path('views/landing.blade.php'));

    expect($html)->toContain('x-data="{ open: false }"')
        ->and($html)->toContain('@click="open = !open"')
        ->and($html)->toContain(":class=\"{'flex': open, 'hidden': !open}\"")
        ->and(substr_count($html, 'x-show='))->toBe(2);
});
