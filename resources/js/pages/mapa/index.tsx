import { Head, usePoll } from '@inertiajs/react';

import {
    COLORES,
    MapaDeCadetes,
    ReferenciaDelMapa,
    type EnElMapa,
    type Punto,
} from '@/components/mapa-de-cadetes';
import { Card } from '@/components/ui/card';

/*
| Dónde están los cadetes que están trabajando desde la app.
|
| ⚠️ Sólo los que están en la app Y tienen posición: es PANEL-18. El endpoint viejo exigía
| estado y no miraba las coordenadas, así que un cadete sin posición llegaba al mapa, que
| armaba el marcador con `parseFloat(null)`.
|
| El mapa en sí vive en `components/mapa-de-cadetes`, porque el detalle de un cadete de la
| cola —en Inicio— dibuja el mismo.
*/

export default function MapaIndex({
    cadetes,
    centro,
    zoom,
    refresco,
    clave,
}: {
    cadetes: EnElMapa[];
    centro: Punto;
    zoom: number;
    refresco: number;
    /** Null si no está configurada: entonces la pantalla lo dice. */
    clave: string | null;
}) {
    // Los cadetes se mueven, así que la pantalla se vuelve a pedir sola. Sólo esa prop.
    usePoll(refresco, { only: ['cadetes'] });

    return (
        <>
            <Head title="Mapa" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">Mapa</h1>
                    <span className="text-muted-foreground text-sm">
                        {cadetes.length === 0
                            ? 'Ningún cadete en la app ahora mismo'
                            : `${cadetes.length} en la app`}
                    </span>
                </div>

                <Card className="relative flex-1 overflow-hidden p-0">
                    <MapaDeCadetes
                        cadetes={cadetes}
                        centro={centro}
                        zoom={zoom}
                        clave={clave}
                        etiqueta="Mapa de cadetes"
                        // Crece con la pantalla. El `min-h` es para que no se aplaste si el
                        // contenedor no tiene alto definido.
                        className="h-full min-h-[60vh] w-full"
                    />
                </Card>

                <Card className="flex-row flex-wrap items-center gap-6 px-4 py-3 text-sm">
                    <ReferenciaDelMapa color={COLORES.bicicleta}>
                        Bicicleta
                    </ReferenciaDelMapa>
                    <ReferenciaDelMapa color={COLORES.motocicleta}>
                        Motocicleta
                    </ReferenciaDelMapa>
                    <ReferenciaDelMapa color={COLORES.conPedido}>
                        Con pedido
                    </ReferenciaDelMapa>
                    <span className="text-muted-foreground text-xs">
                        Sólo los cadetes que están trabajando desde la app.
                    </span>
                </Card>
            </div>
        </>
    );
}

MapaIndex.layout = {
    breadcrumbs: [{ title: 'Mapa', href: '/panel/mapa' }],
};
