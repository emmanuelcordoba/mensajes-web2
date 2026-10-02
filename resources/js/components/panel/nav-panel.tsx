import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

import { ICONOS } from '@/components/panel/iconos';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { ItemDeMenu } from '@/types';

/*
| El menú lateral del panel.
|
| No decide nada: dibuja lo que manda el servidor. Qué entradas hay y quién ve cada una
| está en App\Support\Navegacion, y vive allá para que un test de PHP pueda comparar el
| menú con el middleware de cada ruta — el menú que ofrece lo que la ruta después niega
| es el error que no avisa.
|
| Un rol que no entra al panel recibe la lista vacía y acá no se dibuja nada.
*/

export function NavPanel() {
    const { navegacion } = usePage().props;

    return (
        <>
            {navegacion.map((grupo) => (
                <SidebarGroup
                    key={grupo.titulo ?? 'principal'}
                    className="px-2 py-0"
                >
                    {grupo.titulo && (
                        <SidebarGroupLabel>{grupo.titulo}</SidebarGroupLabel>
                    )}
                    <SidebarMenu>
                        {grupo.items.map((item) => (
                            <Entrada key={item.titulo} item={item} />
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

function Entrada({ item }: { item: ItemDeMenu }) {
    const { isCurrentUrl } = useCurrentUrl();
    const Icono = ICONOS[item.icono];

    if (item.tipo === 'enlace') {
        return (
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    isActive={isCurrentUrl(item.href)}
                    tooltip={{ children: item.titulo }}
                >
                    <Link href={item.href} prefetch>
                        {Icono && <Icono />}
                        <span>{item.titulo}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    }

    return <Desplegable item={item} />;
}

type Agrupada = Extract<ItemDeMenu, { tipo: 'desplegable' }>;

/**
 * Una entrada que agrupa otras, como «Cadetes».
 *
 * Con la barra abierta es un acordeón, y con la barra en modo íconos es un menú flotante
 * al costado. La segunda forma no es un adorno: el submenú desplegado lo esconde el CSS
 * de la barra —`group-data-[collapsible=icon]:hidden` en SidebarMenuSubButton—, así que
 * sin ella un desplegable sería un ícono que no lleva a ninguna parte, y son tres de las
 * nueve entradas. La barra del sistema viejo también abre al costado cuando está
 * plegada.
 *
 * ⚠️ PERO EL CAMBIO DE FORMA ESPERA A QUE MONTE, y eso no es prolijidad: ramificar el
 * árbol según `useSidebar().state` rompe la hidratación. El servidor renderizó el
 * acordeón y el cliente el menú flotante, y React tiró abajo el árbol entero — se veía,
 * la barra quedaba a medio ancho con todos los títulos cortados. El estado de la barra
 * sale de una cookie que el servidor no siempre tiene, y `isMobile` sale de un media
 * query, que del lado del servidor no existe: ninguno de los dos se puede consultar
 * mientras se arma el HTML. Así que la primera pasada dibuja siempre el acordeón, igual
 * que el servidor, y recién después se cambia.
 *
 * Y no se ve el salto porque en modo íconos las dos formas muestran lo mismo: el título
 * pasa a `sr-only` y el chevron se esconde. Eso además arregla un desborde — con los
 * tres elementos adentro de un botón de 32px el `ml-auto` del chevron se repartía el
 * sobrante hacia los dos lados y empujaba el ícono a `left: -19px`, fuera de la caja, de
 * modo que lo único visible era el título cortado al medio: «adet».
 *
 * ⚠️ Abierto es estado y no `defaultOpen`: el menú no se vuelve a montar al navegar entre
 * páginas de Inertia, así que un valor inicial se calcularía una sola vez y el grupo de
 * la pantalla que se está mirando no se abriría solo. El efecto lo abre cuando la
 * pantalla actual pasa a estar adentro, y no lo cierra nunca: si alguien lo abrió a
 * mano, cerrárselo al navegar sería pelearle.
 *
 * El menú viejo era un acordeón de Bootstrap y sólo dejaba uno abierto a la vez
 * (`data-parent="#accordionSidebar"`). Acá pueden quedar varios: son tres grupos de
 * cuatro enlaces, no hay nada que ahorrar cerrándolos.
 */
function Desplegable({ item }: { item: Agrupada }) {
    const { isCurrentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    const Icono = ICONOS[item.icono];

    const tieneLaActual = item.secciones.some((seccion) =>
        seccion.enlaces.some((enlace) => isCurrentUrl(enlace.href)),
    );

    const [abierto, setAbierto] = useState(tieneLaActual);
    const [montado, setMontado] = useState(false);

    useEffect(() => setMontado(true), []);

    useEffect(() => {
        if (tieneLaActual) {
            setAbierto(true);
        }
    }, [tieneLaActual]);

    const cabecera = (
        <>
            {Icono && <Icono />}
            <span className="group-data-[collapsible=icon]:sr-only">
                {item.titulo}
            </span>
            <ChevronRight className="ml-auto transition-transform duration-200 group-data-[collapsible=icon]:hidden group-data-[state=open]/desplegable:rotate-90" />
        </>
    );

    if (montado && state === 'collapsed' && !isMobile) {
        return (
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton isActive={tieneLaActual}>
                            {cabecera}
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        className="min-w-52"
                    >
                        {item.secciones.map((seccion, i) => (
                            <Fragment key={seccion.titulo}>
                                {i > 0 && <DropdownMenuSeparator />}
                                <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">
                                    {seccion.titulo}
                                </DropdownMenuLabel>
                                {seccion.enlaces.map((enlace) => (
                                    <DropdownMenuItem key={enlace.href} asChild>
                                        <Link href={enlace.href} prefetch>
                                            {enlace.titulo}
                                        </Link>
                                    </DropdownMenuItem>
                                ))}
                            </Fragment>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible
            asChild
            open={abierto}
            onOpenChange={setAbierto}
            className="group/desplegable"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        isActive={tieneLaActual}
                        tooltip={{ children: item.titulo }}
                    >
                        {cabecera}
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {item.secciones.map((seccion) => (
                            <Fragment key={seccion.titulo}>
                                {/* El encabezado del bloque, como los <h6> del menú
                                    viejo: en Cadetes separa la gestión de las
                                    cobranzas. */}
                                <li className="text-sidebar-foreground/60 mt-2 px-2 text-xs font-medium first:mt-0">
                                    {seccion.titulo}
                                </li>
                                {seccion.enlaces.map((enlace) => (
                                    <SidebarMenuSubItem key={enlace.href}>
                                        <SidebarMenuSubButton
                                            asChild
                                            isActive={isCurrentUrl(enlace.href)}
                                        >
                                            {/* `title` porque la barra es angosta y
                                                «Horario de Atención APP» no entra: se
                                                corta con puntos suspensivos, y así al
                                                menos se puede leer entero. */}
                                            <Link
                                                href={enlace.href}
                                                title={enlace.titulo}
                                                prefetch
                                            >
                                                <span>{enlace.titulo}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </Fragment>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
