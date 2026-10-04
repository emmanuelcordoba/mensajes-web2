import { Head, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Card } from '@/components/ui/card';

/*
| Dónde están los cadetes que están trabajando desde la app.
|
| ⚠️ Sólo los que están en la app Y tienen posición: es PANEL-18. El endpoint viejo exigía
| estado y no miraba las coordenadas, así que un cadete sin posición llegaba al mapa, que
| armaba el marcador con `parseFloat(null)`.
|
| ⚠️ Los marcadores son símbolos y no las tres imágenes PNG del panel viejo. Mismos tres
| colores y mismo significado; lo que se gana es que escalan y que no hay que arrastrar
| archivos binarios para dibujar un círculo de color.
*/

type EnElMapa = {
    movil: number;
    lat: number;
    lon: number;
    vehiculo: string;
    con_pedido: boolean;
};

type Punto = { lat: number; lng: number };

/*
| ⚠️ Lo poco de la API de Google Maps que esta pantalla usa, declarado acá.
|
| La alternativa es `@types/google.maps`, que son 7.000 líneas de tipos para un mapa, tres
| marcadores y un círculo. Si algún día el mapa hace más que esto, ese paquete es el camino.
*/
type MapaDeGoogle = object;

type MarcadorDeGoogle = { setMap(mapa: MapaDeGoogle | null): void };

type ApiDeGoogleMaps = {
    maps: {
        Map: new (
            elemento: HTMLElement,
            opciones: { zoom: number; center: Punto; mapTypeControl: boolean },
        ) => MapaDeGoogle;
        Marker: new (opciones: {
            position: Punto;
            map: MapaDeGoogle;
            title: string;
            label: { text: string; color: string; fontSize: string };
            icon: {
                path: number;
                scale: number;
                fillColor: string;
                fillOpacity: number;
                strokeColor: string;
                strokeWeight: number;
            };
        }) => MarcadorDeGoogle;
        SymbolPath: { CIRCLE: number };
    };
};

declare global {
    interface Window {
        google?: ApiDeGoogleMaps;
    }
}

/** Los mismos tres del cartel del panel viejo: gris, azul y rojo. */
const COLORES = {
    bicicleta: '#6b7280',
    motocicleta: '#2563eb',
    conPedido: '#dc2626',
};

function colorDe(cadete: EnElMapa): string {
    if (cadete.con_pedido) {
        return COLORES.conPedido;
    }

    return cadete.vehiculo === 'M' ? COLORES.motocicleta : COLORES.bicicleta;
}

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

    const division = useRef<HTMLDivElement>(null);
    const mapa = useRef<MapaDeGoogle | null>(null);
    const marcadores = useRef<MarcadorDeGoogle[]>([]);
    const [listo, setListo] = useState(false);
    const [falló, setFalló] = useState(false);

    // 1. Cargar la API, una sola vez.
    useEffect(() => {
        if (clave === null || clave === '') {
            return;
        }

        if (window.google?.maps !== undefined) {
            setListo(true);

            return;
        }

        const id = 'google-maps';
        const yaEstá = document.getElementById(id) as HTMLScriptElement | null;

        const script = yaEstá ?? document.createElement('script');

        if (yaEstá === null) {
            script.id = id;
            script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(clave)}`;
            script.async = true;
            document.head.appendChild(script);
        }

        const alCargar = () => setListo(true);
        const alFallar = () => setFalló(true);

        script.addEventListener('load', alCargar);
        script.addEventListener('error', alFallar);

        return () => {
            script.removeEventListener('load', alCargar);
            script.removeEventListener('error', alFallar);
        };
    }, [clave]);

    // 2. Crear el mapa cuando la API esté y el div exista.
    useEffect(() => {
        if (!listo || division.current === null || mapa.current !== null) {
            return;
        }

        const api = window.google;

        if (api === undefined) {
            return;
        }

        mapa.current = new api.maps.Map(division.current, {
            zoom,
            center: centro,
            mapTypeControl: false,
        });
    }, [listo, centro, zoom]);

    // 3. Redibujar los marcadores cada vez que cambian las posiciones.
    //
    // Se borran todos y se vuelven a crear, como hacía `deleteMarkers()` en el panel
    // viejo. Con nueve marcadores no hay nada que optimizar, y mover los existentes sería
    // más código para que un cadete que deja la app desaparezca.
    useEffect(() => {
        const api = window.google;

        if (!listo || mapa.current === null || api === undefined) {
            return;
        }

        marcadores.current.forEach((marcador) => marcador.setMap(null));

        marcadores.current = cadetes.map(
            (cadete) =>
                new api.maps.Marker({
                    position: { lat: cadete.lat, lng: cadete.lon },
                    map: mapa.current as MapaDeGoogle,
                    title: `Móvil ${cadete.movil}`,
                    label: {
                        text: String(cadete.movil),
                        color: '#fff',
                        fontSize: '11px',
                    },
                    icon: {
                        path: api.maps.SymbolPath.CIRCLE,
                        scale: 14,
                        fillColor: colorDe(cadete),
                        fillOpacity: 1,
                        strokeColor: '#fff',
                        strokeWeight: 2,
                    },
                }),
        );
    }, [cadetes, listo]);

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
                    {clave === null || clave === '' ? (
                        <SinClave />
                    ) : falló ? (
                        <Aviso>
                            No se pudo cargar el mapa de Google. Puede ser la conexión, o
                            que la clave no tenga habilitado este dominio.
                        </Aviso>
                    ) : (
                        <>
                            <div
                                ref={division}
                                // Crece con la pantalla. El `min-h` es para que no se
                                // aplaste si el contenedor no tiene alto definido.
                                className="h-full min-h-[60vh] w-full"
                                role="application"
                                aria-label="Mapa de cadetes"
                            />
                            {!listo && (
                                <Aviso>Cargando el mapa…</Aviso>
                            )}
                        </>
                    )}
                </Card>

                <Card className="flex-row flex-wrap items-center gap-6 px-4 py-3 text-sm">
                    <Referencia color={COLORES.bicicleta}>Bicicleta</Referencia>
                    <Referencia color={COLORES.motocicleta}>Motocicleta</Referencia>
                    <Referencia color={COLORES.conPedido}>Con pedido</Referencia>
                    <span className="text-muted-foreground text-xs">
                        Sólo los cadetes que están trabajando desde la app.
                    </span>
                </Card>
            </div>
        </>
    );
}

function Referencia({
    color,
    children,
}: {
    color: string;
    children: React.ReactNode;
}) {
    return (
        <span className="flex items-center gap-2">
            <span
                className="size-4 rounded-full border-2 border-white"
                style={{ backgroundColor: color }}
            />
            {children}
        </span>
    );
}

function Aviso({ children }: { children: React.ReactNode }) {
    return (
        <div className="bg-card text-muted-foreground absolute inset-0 flex items-center justify-center p-8 text-center text-sm">
            {children}
        </div>
    );
}

/**
 * Sin clave no hay mapa.
 *
 * Se dice en vez de dibujar un mapa roto, y se dice qué falta: es una instalación a medio
 * configurar, no algo que se arregle desde esta pantalla.
 */
function SinClave() {
    return (
        <Aviso>
            <span>
                Falta configurar <code>GOOGLE_MAPS_KEY</code> en el entorno. Sin esa clave
                la API de Google Maps no carga, y el mapa no se puede dibujar.
            </span>
        </Aviso>
    );
}

MapaIndex.layout = {
    breadcrumbs: [{ title: 'Mapa', href: '/panel/mapa' }],
};
