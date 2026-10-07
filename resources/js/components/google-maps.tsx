import { useEffect, useState } from 'react';

/*
| La API de Google Maps: un solo cargador para todo el panel.
|
| Tres pantallas la usan —el Mapa del menú, el detalle de un cadete en Inicio y el alta de
| pedidos, que autocompleta direcciones y mide el viaje—, y el `<script>` va UNA vez en el
| documento: dos cargadores compitiendo por `window.google` es justo el error difícil de
| encontrar. Por eso el cargador vive acá y no en el componente del mapa.
|
| ⚠️ **La URL lleva `libraries=places` para todos**, aunque sólo el alta use Places. Si cada
| pantalla pidiera lo suyo, la primera que cargue decide qué hay en `window.google` para las
| demás: abrir Inicio con el mapa del detalle antes que el alta dejaría el alta sin
| autocompletado, de manera intermitente y sin ningún error a la vista. Una URL, una vez.
|
| ⚠️ **Los tipos están escritos a mano, y sólo lo que se usa.** La alternativa es
| `@types/google.maps`: 7.000 líneas para un mapa, unos marcadores, un autocompletado y una
| ruta. Si algún día esto hace bastante más, ese paquete es el camino.
*/

export type Punto = { lat: number; lng: number };

export type MapaDeGoogle = {
    setCenter(punto: Punto): void;
    setZoom(zoom: number): void;
};

export type MarcadorDeGoogle = { setMap(mapa: MapaDeGoogle | null): void };

/** Un resultado de Places: lo único que se mira es el `place_id` y el texto. */
export type Prediccion = { place_id: string; description: string };

/** Los pedazos de una dirección, que es como Places la devuelve. */
export type ParteDeDireccion = { types: string[]; long_name: string };

/** Una ruta: de todo lo que contesta Directions, se usa la distancia en metros. */
export type Ruta = {
    routes: { legs: { distance?: { value: number } }[] }[];
};

export type PedidoDeRuta = {
    origin: { placeId: string };
    destination: { placeId: string };
    travelMode: string;
};

export type ApiDeGoogleMaps = {
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

        LatLng: new (lat: number, lng: number) => object;
        LatLngBounds: new (suroeste: object, noreste: object) => object;

        TravelMode: { DRIVING: string };

        DirectionsService: new () => {
            route(
                pedido: PedidoDeRuta,
                respuesta: (ruta: Ruta | null, estado: string) => void,
            ): void;
        };

        DirectionsRenderer: new (opciones: { map: MapaDeGoogle }) => {
            setDirections(ruta: Ruta): void;
            setMap(mapa: MapaDeGoogle | null): void;
        };

        places: {
            AutocompleteService: new () => {
                getPlacePredictions(
                    pedido: {
                        input: string;
                        componentRestrictions: { country: string };
                        types: string[];
                        locationBias: object;
                    },
                    respuesta: (
                        predicciones: Prediccion[] | null,
                        estado: string,
                    ) => void,
                ): void;
            };
            PlacesService: new (mapa: HTMLElement) => {
                getDetails(
                    pedido: { placeId: string; fields: string[] },
                    respuesta: (
                        lugar: { address_components?: ParteDeDireccion[] } | null,
                        estado: string,
                    ) => void,
                ): void;
            };
            PlacesServiceStatus: { OK: string };
        };
    };
};

declare global {
    interface Window {
        google?: ApiDeGoogleMaps;
    }
}

export type EstadoDeLaApi = {
    /** La API está cargada y `api` se puede usar. */
    listo: boolean;
    /** No se pudo cargar: la conexión, o la clave sin este dominio habilitado. */
    falló: boolean;
    api: ApiDeGoogleMaps | undefined;
};

/**
 * Carga la API de Google Maps, una sola vez en el documento.
 *
 * Con la clave en null —no está configurada— no intenta nada: quien llama dice qué falta,
 * en vez de dibujar algo roto.
 */
export function useApiDeGoogleMaps(clave: string | null): EstadoDeLaApi {
    const [listo, setListo] = useState(window.google?.maps !== undefined);
    const [falló, setFalló] = useState(false);

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
            script.src =
                'https://maps.googleapis.com/maps/api/js?libraries=places&key=' +
                encodeURIComponent(clave);
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

    return { listo, falló, api: listo ? window.google : undefined };
}

/** Lo que se dice en lugar de un mapa: por qué no hay mapa. */
export function Aviso({ children }: { children: React.ReactNode }) {
    return (
        <div className="bg-card text-muted-foreground absolute inset-0 flex items-center justify-center p-8 text-center text-sm">
            {children}
        </div>
    );
}

/** El mismo texto en las tres pantallas que pueden quedarse sin clave. */
export function FaltaLaClave() {
    return (
        <Aviso>
            <span>
                Falta configurar <code>GOOGLE_MAPS_KEY</code> en el entorno. Sin esa clave
                la API de Google Maps no carga, y el mapa no se puede dibujar.
            </span>
        </Aviso>
    );
}
