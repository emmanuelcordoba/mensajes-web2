import { useEffect, useRef, useState } from 'react';

/*
| El mapa de Google con cadetes encima.
|
| Lo usan dos pantallas: el Mapa del menú y el detalle de un cadete de la cola, en Inicio. Es
| un componente y no dos copias porque lo que se repetiría es el cargador de la API —el
| `<script>` que hay que meter una sola vez en el documento aunque haya dos mapas—, y dos
| cargadores compitiendo por `window.google` es justo el error difícil de encontrar.
|
| ⚠️ Los marcadores son símbolos y no las tres imágenes PNG del panel viejo. Mismos tres
| colores y mismo significado; lo que se gana es que escalan y que no hay que arrastrar
| archivos binarios para dibujar un círculo de color.
*/

export type EnElMapa = {
    movil: number;
    lat: number;
    lon: number;
    vehiculo: string;
    con_pedido: boolean;
};

export type Punto = { lat: number; lng: number };

/*
| ⚠️ Lo poco de la API de Google Maps que esto usa, declarado acá.
|
| La alternativa es `@types/google.maps`, que son 7.000 líneas de tipos para un mapa, unos
| marcadores y un círculo. Si algún día el mapa hace más que esto, ese paquete es el camino.
*/
type MapaDeGoogle = {
    setCenter(punto: Punto): void;
    setZoom(zoom: number): void;
};

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
export const COLORES = {
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

export function MapaDeCadetes({
    cadetes,
    centro,
    zoom,
    clave,
    className,
    etiqueta,
}: {
    cadetes: EnElMapa[];
    centro: Punto;
    zoom: number;
    /** Null si no está configurada: entonces se dice, en vez de dibujar un mapa roto. */
    clave: string | null;
    className?: string;
    etiqueta: string;
}) {
    const division = useRef<HTMLDivElement>(null);
    const mapa = useRef<MapaDeGoogle | null>(null);
    const marcadores = useRef<MarcadorDeGoogle[]>([]);
    const [listo, setListo] = useState(false);
    const [falló, setFalló] = useState(false);

    // 1. Cargar la API, una sola vez en todo el documento.
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

    // 3. Recentrar cuando el que llama cambia de centro.
    //
    // Hace falta para el detalle de un cadete: el mismo mapa pasa de un cadete a otro sin
    // volver a crearse, y sin esto se quedaría mirando al primero.
    useEffect(() => {
        if (mapa.current === null) {
            return;
        }

        mapa.current.setCenter(centro);
        mapa.current.setZoom(zoom);
    }, [centro, zoom]);

    // 4. Redibujar los marcadores cada vez que cambian las posiciones.
    //
    // Se borran todos y se vuelven a crear, como hacía `deleteMarkers()` en el panel viejo.
    // Con nueve marcadores no hay nada que optimizar, y mover los existentes sería más
    // código para que un cadete que deja la app desaparezca.
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

    if (clave === null || clave === '') {
        return (
            <Aviso>
                <span>
                    Falta configurar <code>GOOGLE_MAPS_KEY</code> en el entorno. Sin esa
                    clave la API de Google Maps no carga, y el mapa no se puede dibujar.
                </span>
            </Aviso>
        );
    }

    if (falló) {
        return (
            <Aviso>
                No se pudo cargar el mapa de Google. Puede ser la conexión, o que la clave
                no tenga habilitado este dominio.
            </Aviso>
        );
    }

    return (
        <>
            <div
                ref={division}
                className={className}
                role="application"
                aria-label={etiqueta}
            />
            {!listo && <Aviso>Cargando el mapa…</Aviso>}
        </>
    );
}

export function Aviso({ children }: { children: React.ReactNode }) {
    return (
        <div className="bg-card text-muted-foreground absolute inset-0 flex items-center justify-center p-8 text-center text-sm">
            {children}
        </div>
    );
}

export function ReferenciaDelMapa({
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
