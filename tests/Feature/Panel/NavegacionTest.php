<?php

use App\Models\Rol;
use App\Models\User;
use App\Support\Navegacion;

/*
| El menú del panel.
|
| El menú y el middleware de cada ruta dicen lo mismo con palabras distintas: uno ofrece
| una pantalla y el otro deja entrar. Cuando se separan, el que se equivoca en silencio
| es el menú — ofrece un botón que después da 403 —, y por eso los dos tests centrales de
| acá no comprueban el menú contra una lista escrita a mano, sino contra el router:
| recorren cada enlace con cada rol y piden la página.
|
| Es la misma forma que `porQueNo()` en el sistema viejo: una regla, dos lectores, un
| test. Y es la razón por la que el menú vive en PHP y no en TypeScript.
*/

/**
 * Todos los href que el menú le ofrece a alguien, aplanados.
 *
 * ⚠️ Con nombre propio y no `enlaces()`: una función declarada en un archivo de tests es
 * global igual, así que el segundo archivo que declarara el mismo nombre revienta por
 * redeclaración. Ver el comentario de `usuarioCon()` en Pest.php.
 *
 * @return list<string>
 */
function enlacesDelMenuDe(?User $user): array
{
    $href = [];

    foreach (Navegacion::para($user) as $grupo) {
        foreach ($grupo['items'] as $item) {
            if ($item['tipo'] === 'enlace') {
                $href[] = $item['href'];

                continue;
            }

            foreach ($item['secciones'] as $seccion) {
                foreach ($seccion['enlaces'] as $enlace) {
                    $href[] = $enlace['href'];
                }
            }
        }
    }

    return $href;
}

test('every screen the menu offers is one that role can open', function (string $rol) {
    $user = usuarioCon($rol);
    $enlaces = enlacesDelMenuDe($user);

    expect($enlaces)->not->toBeEmpty();

    foreach ($enlaces as $href) {
        $this->actingAs($user)
            ->get($href)
            ->assertSuccessful();
    }
})->with([
    'admin' => Rol::ADMIN,
    'empleado' => Rol::EMPLEADO,
    'comercio' => Rol::RESTRINGIDO,
]);

test('every screen the menu hides from a shop is one a shop cannot open', function () {
    // ⚠️ El inverso, y es el que importa: que el menú no muestre la administración no
    // sirve de nada si la ruta la deja entrar igual escribiendo la URL. Esto fue SEC-4
    // en el sistema viejo: una cuenta de comercio llegaba a la administración de
    // usuarios y podía crearse un administrador.
    $comercio = usuarioCon(Rol::RESTRINGIDO);
    $suyos = enlacesDelMenuDe($comercio);
    $ajenos = array_diff(enlacesDelMenuDe(usuarioCon(Rol::ADMIN)), $suyos);

    expect($ajenos)->not->toBeEmpty();

    foreach ($ajenos as $href) {
        $this->actingAs($comercio)
            ->get($href)
            ->assertForbidden();
    }
});

test('a shop gets the two screens it works with, and nothing else', function () {
    $menu = Navegacion::para(usuarioCon(Rol::RESTRINGIDO));

    // Un solo grupo, el que no tiene encabezado: el grupo «Admin» no viaja vacío, se va
    // con sus entradas.
    expect($menu)->toHaveCount(1)
        ->and($menu[0]['titulo'])->toBeNull()
        ->and(array_column($menu[0]['items'], 'titulo'))->toBe(['Inicio', 'Pedidos']);
});

test('the office gets the same menu as the old sidebar', function (string $rol) {
    // Las entradas del menú lateral viejo, en su orden. Si alguna se cae, acá se nota.
    $menu = Navegacion::para(usuarioCon($rol));

    expect(array_column($menu, 'titulo'))->toBe([null, 'Admin'])
        ->and(array_column($menu[0]['items'], 'titulo'))->toBe(['Inicio', 'Pedidos', 'Chat'])
        ->and(array_column($menu[1]['items'], 'titulo'))->toBe([
            'Clientes', 'Cadetes', 'Usuarios', 'Configuraciones', 'Mapa', 'Estadísticas',
        ]);
})->with([
    'admin' => Rol::ADMIN,
    'empleado' => Rol::EMPLEADO,
]);

test('the groups keep the headings the old sidebar had', function () {
    $menu = Navegacion::para(usuarioCon(Rol::EMPLEADO));
    $admin = collect($menu[1]['items'])->keyBy('titulo');

    expect(array_column($admin['Clientes']['secciones'], 'titulo'))->toBe(['Gestión de clientes'])
        // Cadetes es el único con dos bloques, y la separación es información: la
        // gestión del cadete no es lo mismo que cobrarle.
        ->and(array_column($admin['Cadetes']['secciones'], 'titulo'))->toBe(['Gestión de cadetes', 'Cobranzas'])
        ->and(array_column($admin['Usuarios']['secciones'], 'titulo'))->toBe(['Gestión de usuarios']);
});

test('nobody outside the panel gets a menu', function () {
    expect(Navegacion::para(null))->toBe([])
        // Las 20 cuentas activas sin rol_id que hay en producción, y las de cadete.
        ->and(Navegacion::para(User::factory()->create(['rol_id' => null])))->toBe([]);
});

test('the menu travels with every page, already cut by role', function () {
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/pedidos')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('navegacion.0.items.0.titulo', 'Inicio')
            ->where('navegacion.0.items.0.href', '/panel')
            // Una sola, y es la que confirma que el recorte lo hace el servidor: la
            // administración no viaja escondida para que el frontend la tape.
            ->count('navegacion', 1)
        );
});

test('the menu sends only what the frontend reads', function () {
    $menu = Navegacion::para(usuarioCon(Rol::ADMIN));

    // Ni el rol ni el nombre de la ruta: el frontend lee título, ícono y URL. Es lo
    // mismo que se hizo con auth.user, donde seis columnas internas salían por omisión.
    expect(array_keys($menu[0]['items'][0]))->toBe(['tipo', 'titulo', 'icono', 'href'])
        ->and(array_keys($menu[1]['items'][0]))->toBe(['tipo', 'titulo', 'icono', 'secciones']);
});

test('a screen that is not built yet says its own name', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('panel/pendiente')
            // El nombre sale del menú, así que está escrito en un solo lado.
            ->where('titulo', 'Saldo')
            ->where('dentroDe', 'Cadetes')
        );
});

test('a screen that is not inside a group has nothing above it', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('panel/pendiente')
            ->where('titulo', 'Mapa')
            ->where('dentroDe', null)
        );
});

test('the home screen of the panel is the one the old system opens on', function () {
    // `/panel`, la misma URL: es donde cae alguien que escribe la dirección a secas y
    // donde apunta la marca del menú.
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->where('titulo', 'Inicio'));
});

test('every link is relative, so no page carries the host around', function () {
    foreach (enlacesDelMenuDe(usuarioCon(Rol::ADMIN)) as $href) {
        expect($href)->toStartWith('/panel');
    }
});
