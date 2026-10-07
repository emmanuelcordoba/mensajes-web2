import { useEffect, useRef } from 'react';

import {
    Aviso,
    FaltaLaClave,
    useApiDeGoogleMaps,
    type MapaDeGoogle,
    type MarcadorDeGoogle,
    type Punto,
} from '@/components/google-maps';

/*
| El mapa de Google con cadetes encima.
|
| Lo usan dos pantallas: el Mapa del menú y el detalle de un cadete de la cola, en Inicio. Es
| un componente y no dos copias porque lo que se repetiría es el dibujado de los marcadores.
|
| ⚠️ El cargador de la API no está acá: vive en `components/google-maps`, porque el alta de
| pedidos también la necesita —para el autocompletado de direcciones— y el `<script>` tiene
| que ir una sola vez en el documento.
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

export { Aviso };
export type { Punto };

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
    const { listo, falló, api } = useApiDeGoogleMaps(clave);

    // 1. Crear el mapa cuando la API esté y el div exista.
    useEffect(() => {
        if (!listo || division.current === null || mapa.current !== null) {
            return;
        }

        if (api === undefined) {
            return;
        }

        mapa.current = new api.maps.Map(division.current, {
            zoom,
            center: centro,
            mapTypeControl: false,
        });
    }, [listo, api, centro, zoom]);

    // 2. Recentrar cuando el que llama cambia de centro.
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

    // 3. Redibujar los marcadores cada vez que cambian las posiciones.
    //
    // Se borran todos y se vuelven a crear, como hacía `deleteMarkers()` en el panel viejo.
    // Con nueve marcadores no hay nada que optimizar, y mover los existentes sería más
    // código para que un cadete que deja la app desaparezca.
    useEffect(() => {
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
    }, [cadetes, listo, api]);

    if (clave === null || clave === '') {
        return <FaltaLaClave />;
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
