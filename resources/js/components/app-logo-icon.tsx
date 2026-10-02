import { Bike } from 'lucide-react';
import type { ComponentProps } from 'react';

/**
 * La marca.
 *
 * Una bicicleta, que es la del menú lateral del sistema viejo (`fa-biking`). Antes era
 * el logo de Laravel, que viene con el starter kit y no es la marca de nada de acá.
 *
 * ⚠️ Es un ícono de trazo y no de relleno, así que quien lo use NO tiene que pasarle
 * `fill-current`: esa clase le gana al `fill="none"` del SVG y lo deja como una manchón.
 * El color va con `text-*`, que es lo que pinta el trazo.
 */
export default function AppLogoIcon(props: ComponentProps<typeof Bike>) {
    return <Bike {...props} />;
}
