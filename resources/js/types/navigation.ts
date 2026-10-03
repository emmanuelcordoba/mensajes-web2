import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/*
| El menú del panel, como lo manda el servidor.
|
| La forma la define App\Support\Navegacion, que es donde vive el menú: acá sólo se
| escribe qué forma tiene lo que llega. El docblock de esa clase dice por qué está allá
| y no acá.
*/

/**
 * Los íconos que el menú sabe dibujar, con los nombres de lucide.
 *
 * ⚠️ Esta lista y la de App\Support\Navegacion tienen que coincidir: el servidor manda
 * el nombre como texto y TypeScript no puede comprobarlo. Si agregás un ícono allá,
 * agregalo acá y en ICONOS —components/panel/iconos.ts—, que es un Record sobre este
 * tipo y por eso sí falla si le falta uno.
 */
export type IconoDeMenu =
    | 'chart-line'
    | 'contact'
    | 'footprints'
    | 'gauge'
    | 'list'
    | 'map-pinned'
    | 'messages-square'
    | 'settings'
    | 'users';

export type EnlaceDeMenu = {
    titulo: string;
    href: string;
    /**
     * Si se abre en otra pestaña.
     *
     * Hoy lo es uno solo: la cola del mostrador, que vive en un monitor aparte y no
     * tiene menú. Abrirla en la pestaña del panel dejaría a la oficina sin su panel.
     */
    nuevaPestania: boolean;
};

/** Un bloque de enlaces con su encabezado, dentro de un desplegable. */
export type SeccionDeMenu = {
    titulo: string;
    enlaces: EnlaceDeMenu[];
};

export type ItemDeMenu =
    | { tipo: 'enlace'; titulo: string; icono: IconoDeMenu; href: string }
    | {
          tipo: 'desplegable';
          titulo: string;
          icono: IconoDeMenu;
          secciones: SeccionDeMenu[];
      };

/** `titulo` en null es un grupo sin encabezado: las primeras entradas del menú. */
export type GrupoDeMenu = {
    titulo: string | null;
    items: ItemDeMenu[];
};

/**
 * El tiempo de demora que la barra de arriba deja cambiar.
 *
 * Llega en null para quien no puede cambiarlo —un comercio—, y entonces el selector no se
 * dibuja. Ver HandleInertiaRequests::tiempoDeDemora().
 */
export type TiempoDeDemora = {
    id: number;
    /** null es «sin indicar», que es un estado válido. */
    valor: string | null;
    opciones: string[];
};
