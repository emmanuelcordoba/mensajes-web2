import {
    ChartLine,
    Contact,
    Footprints,
    Gauge,
    List,
    MapPinned,
    MessagesSquare,
    Settings,
    Users,
    type LucideIcon,
} from 'lucide-react';

import type { IconoDeMenu } from '@/types';

/**
 * El ícono de cada entrada del menú.
 *
 * El servidor manda el nombre y acá se resuelve, porque un componente de React no se
 * puede serializar. Al estar tipado como Record sobre IconoDeMenu, TypeScript no deja
 * que falte ninguno; lo que no puede comprobar es que el servidor mande un nombre de la
 * lista, así que quien dibuja tolera que no esté. Ver App\Support\Navegacion.
 *
 * Los de lucide que reemplazan a los de FontAwesome del sistema viejo; el nombre viejo
 * queda anotado en Navegacion, al lado de cada entrada.
 */
export const ICONOS: Record<IconoDeMenu, LucideIcon> = {
    'chart-line': ChartLine,
    contact: Contact,
    footprints: Footprints,
    gauge: Gauge,
    list: List,
    'map-pinned': MapPinned,
    'messages-square': MessagesSquare,
    settings: Settings,
    users: Users,
};
