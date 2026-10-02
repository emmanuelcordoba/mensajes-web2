<?php

namespace App\Support;

use App\Models\Rol;
use App\Models\User;

/**
 * El menú del panel: qué entradas tiene y quién ve cada una.
 *
 * Es el menú lateral del sistema viejo —`template/partials/sidebar.blade.php`— con las
 * mismas entradas, los mismos textos y el mismo recorte por rol. Lo que cambia es lo
 * que es: allá era HTML con `@if(Auth::user()->tieneRol([...]))` intercalado entre los
 * enlaces, acá son datos que el servidor manda y el frontend dibuja.
 *
 * ⚠️ VIVE EN EL SERVIDOR Y NO EN EL FRONTEND, y la razón es que así se puede
 * comprobar. El menú y el middleware de cada ruta dicen lo mismo con palabras
 * distintas, y cuando se separan el que se equivoca en silencio es el menú: ofrece un
 * botón que después da 403. `NavegacionTest` recorre cada entrada con cada rol y
 * compara las dos respuestas. Si el menú estuviera en TypeScript, ningún test de PHP
 * podría hacerlo. Es lo mismo que `porQueNo()` en el sistema viejo: una regla, dos
 * lectores, un test.
 *
 * ⚠️ Y un `restringido` no recibe las entradas de administración: no se esconden con
 * CSS, no viajan. El rol decide en `para()`, antes de que la página se arme.
 *
 * Las entradas del menú viejo que NO están acá, a propósito:
 *
 * - **Corrección de datos**, el grupo entero —y «Con Numero repetido» dentro de
 *   Clientes, que apunta al mismo lugar que una de ellas—. Son las pantallas de
 *   DATA-6, 7 y 8: existen para arreglar a mano lo que el esquema nuevo rechaza, y se
 *   borran en el corte. Acá no tendrían nada que listar: la base nueva no deja entrar
 *   un email repetido ni un DNI inválido. Portarlas sería traer el andamio con el
 *   edificio.
 * - **Profile**, **Settings** y **Activity Log** del menú de usuario, que en el
 *   sistema viejo son `href="#"` y no van a ninguna parte —son del template SB Admin
 *   2, en inglés, nunca se conectaron—. Acá ese lugar existe de verdad y es
 *   `/settings`, que ya lo pone el menú de usuario.
 *
 * @phpstan-type Enlace array{titulo: string, ruta: string}
 * @phpstan-type Seccion array{titulo: string, enlaces: list<Enlace>}
 * @phpstan-type Item array{tipo: 'enlace', titulo: string, icono: string, roles: list<string>, ruta: string}|array{tipo: 'desplegable', titulo: string, icono: string, roles: list<string>, secciones: list<Seccion>}
 * @phpstan-type Grupo array{titulo: string|null, items: list<Item>}
 * @phpstan-type EnlaceVisible array{titulo: string, href: string}
 * @phpstan-type SeccionVisible array{titulo: string, enlaces: list<EnlaceVisible>}
 * @phpstan-type ItemVisible array{tipo: 'enlace', titulo: string, icono: string, href: string}|array{tipo: 'desplegable', titulo: string, icono: string, secciones: list<SeccionVisible>}
 * @phpstan-type GrupoVisible array{titulo: string|null, items: list<ItemVisible>}
 */
class Navegacion
{
    /**
     * El menú entero, con los roles de cada entrada. Es la definición; `para()` es lo
     * que de ella le llega a una persona.
     *
     * ⚠️ Los nombres de `icono` son de lucide, y la lista de los que el frontend sabe
     * dibujar está en `resources/js/types/navigation.ts` (`IconoDeMenu`). Si agregás
     * uno acá, agregalo allá: si no, la entrada sale sin ícono. Entre paréntesis queda
     * el de FontAwesome que usaba el sistema viejo, para poder comparar.
     *
     * @return list<Grupo>
     */
    public static function definicion(): array
    {
        $oficina = [Rol::ADMIN, Rol::EMPLEADO];
        $todos = [Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO];

        return [
            [
                // Sin encabezado, como en el sistema viejo: son las entradas que están
                // antes del primer `sidebar-heading`.
                'titulo' => null,
                'items' => [
                    // Las dos únicas que el menú viejo muestra sin condición de rol, y
                    // las dos que un comercio necesita para trabajar.
                    ['tipo' => 'enlace', 'titulo' => 'Inicio', 'icono' => 'gauge', 'roles' => $todos, 'ruta' => 'panel.inicio'], // fa-tachometer-alt
                    ['tipo' => 'enlace', 'titulo' => 'Pedidos', 'icono' => 'list', 'roles' => $todos, 'ruta' => 'panel.pedidos.index'], // fa-list
                    ['tipo' => 'enlace', 'titulo' => 'Chat', 'icono' => 'messages-square', 'roles' => $oficina, 'ruta' => 'panel.mensajes.index'], // fa-comments
                ],
            ],
            [
                // El encabezado del menú viejo, tal cual. Separa lo que un comercio usa
                // para trabajar de lo que es administrar el negocio.
                'titulo' => 'Admin',
                'items' => [
                    [
                        'tipo' => 'desplegable', 'titulo' => 'Clientes', 'icono' => 'contact', 'roles' => $oficina, // fa-address-card
                        'secciones' => [
                            // Los encabezados del sistema viejo van sin los dos puntos
                            // y en una sola capitalización: allá alternan
                            // «Gestión de clientes» con «Gestión de Cadetes:».
                            ['titulo' => 'Gestión de clientes', 'enlaces' => [
                                ['titulo' => 'Nuevo cliente', 'ruta' => 'panel.clientes.create'],
                                ['titulo' => 'Clientes', 'ruta' => 'panel.clientes.index'],
                                ['titulo' => 'Publicidad APP', 'ruta' => 'panel.publicidad-app.index'],
                                ['titulo' => 'Horario de Atención APP', 'ruta' => 'panel.horario-de-atencion.index'],
                            ]],
                        ],
                    ],
                    [
                        'tipo' => 'desplegable', 'titulo' => 'Cadetes', 'icono' => 'footprints', 'roles' => $oficina, // fa-walking
                        'secciones' => [
                            ['titulo' => 'Gestión de cadetes', 'enlaces' => [
                                ['titulo' => 'Nuevo cadete', 'ruta' => 'panel.cadetes.create'],
                                ['titulo' => 'Cadetes', 'ruta' => 'panel.cadetes.index'],
                                ['titulo' => 'Postulaciones', 'ruta' => 'panel.cadetes.postulaciones.index'],
                                ['titulo' => 'Agregar cadete a cola', 'ruta' => 'panel.cadetes.cola'],
                            ]],
                            ['titulo' => 'Cobranzas', 'enlaces' => [
                                ['titulo' => 'Semanal', 'ruta' => 'panel.cadetes.cobranzas.semanal.index'],
                                ['titulo' => 'Saldo', 'ruta' => 'panel.cadetes.cobranzas.saldo.index'],
                            ]],
                        ],
                    ],
                    [
                        'tipo' => 'desplegable', 'titulo' => 'Usuarios', 'icono' => 'users', 'roles' => $oficina, // fa-users
                        'secciones' => [
                            ['titulo' => 'Gestión de usuarios', 'enlaces' => [
                                ['titulo' => 'Nuevo usuario', 'ruta' => 'panel.users.create'],
                                ['titulo' => 'Usuarios', 'ruta' => 'panel.users.index'],
                            ]],
                        ],
                    ],
                    ['tipo' => 'enlace', 'titulo' => 'Configuraciones', 'icono' => 'settings', 'roles' => $oficina, 'ruta' => 'panel.configuraciones.index'], // fa-cog
                    ['tipo' => 'enlace', 'titulo' => 'Mapa', 'icono' => 'map-pinned', 'roles' => $oficina, 'ruta' => 'panel.mapa'], // fa-map-marked
                    ['tipo' => 'enlace', 'titulo' => 'Estadísticas', 'icono' => 'chart-line', 'roles' => $oficina, 'ruta' => 'panel.estadisticas.index'], // fa-chart-line
                ],
            ],
        ];
    }

    /**
     * El menú que le toca a quien está mirando, con las URL ya resueltas.
     *
     * Devuelve `[]` para quien no entra al panel: un invitado, y también una cuenta de
     * cadete o una de las 20 que en producción no tienen rol. Un grupo cuyos items se
     * fueron todos no se manda vacío, se va con ellos.
     *
     * @return list<GrupoVisible>
     */
    public static function para(?User $user): array
    {
        $rol = $user?->rol?->rol;

        if ($rol === null) {
            return [];
        }

        $grupos = [];

        foreach (self::definicion() as $grupo) {
            $items = [];

            foreach ($grupo['items'] as $item) {
                if (! in_array($rol, $item['roles'], true)) {
                    continue;
                }

                // Sale el rol y sale el nombre de la ruta: el frontend lee el título,
                // el ícono y la URL, y nada más. Lo mismo que `auth.user`.
                $items[] = $item['tipo'] === 'enlace'
                    ? [
                        'tipo' => 'enlace',
                        'titulo' => $item['titulo'],
                        'icono' => $item['icono'],
                        'href' => self::href($item['ruta']),
                    ]
                    : [
                        'tipo' => 'desplegable',
                        'titulo' => $item['titulo'],
                        'icono' => $item['icono'],
                        'secciones' => self::secciones($item['secciones']),
                    ];
            }

            if ($items !== []) {
                $grupos[] = ['titulo' => $grupo['titulo'], 'items' => $items];
            }
        }

        return $grupos;
    }

    /**
     * Dónde está una ruta dentro del menú: cómo se llama la entrada y, si cuelga de un
     * desplegable, cómo se llama el desplegable.
     *
     * Lo usa `PendienteController` para que el nombre de una pantalla esté escrito en
     * un solo lado. Devuelve `null` para una ruta que el menú no enlaza.
     *
     * @return array{titulo: string, dentroDe: string|null}|null
     */
    public static function ubicar(string $ruta): ?array
    {
        foreach (self::definicion() as $grupo) {
            foreach ($grupo['items'] as $item) {
                if ($item['tipo'] === 'enlace') {
                    if ($item['ruta'] === $ruta) {
                        return ['titulo' => $item['titulo'], 'dentroDe' => null];
                    }

                    continue;
                }

                foreach ($item['secciones'] as $seccion) {
                    foreach ($seccion['enlaces'] as $enlace) {
                        if ($enlace['ruta'] === $ruta) {
                            return ['titulo' => $enlace['titulo'], 'dentroDe' => $item['titulo']];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Seccion>  $secciones
     * @return list<SeccionVisible>
     */
    protected static function secciones(array $secciones): array
    {
        return array_map(fn (array $seccion): array => [
            'titulo' => $seccion['titulo'],
            'enlaces' => array_map(fn (array $enlace): array => [
                'titulo' => $enlace['titulo'],
                'href' => self::href($enlace['ruta']),
            ], $seccion['enlaces']),
        ], $secciones);
    }

    /**
     * La URL de una ruta, relativa.
     *
     * Relativa y no absoluta porque es con lo que el frontend compara para saber qué
     * entrada está abierta: `useCurrentUrl()` mira el `pathname`. Y porque el nombre
     * del host no tiene por qué viajar en cada página.
     */
    protected static function href(string $ruta): string
    {
        return route($ruta, [], false);
    }
}
