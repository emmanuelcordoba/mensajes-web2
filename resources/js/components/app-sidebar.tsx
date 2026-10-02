import { Link } from '@inertiajs/react';

import AppLogo from '@/components/app-logo';
import { NavUser } from '@/components/nav-user';
import { NavPanel } from '@/components/panel/nav-panel';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

/*
| La barra lateral del panel.
|
| Las entradas las manda el servidor, recortadas por rol: ver App\Support\Navegacion y
| NavPanel. Acá sólo está el armazón — la marca arriba, el menú en el medio y quién está
| mirando abajo —, que es el mismo del sistema viejo.
|
| Se fueron los dos enlaces que traía el starter kit al pie, «Repository» y
| «Documentation»: son a la documentación de Laravel, no a nada de este panel.
*/

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            {/* Al inicio, como la marca del menú viejo. */}
                            <Link href="/panel" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavPanel />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
