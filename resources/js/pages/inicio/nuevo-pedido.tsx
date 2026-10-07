import { Form } from '@inertiajs/react';
import { Map, Printer, UserPlus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
    FaltaLaClave,
    useApiDeGoogleMaps,
    type MapaDeGoogle,
    type ParteDeDireccion,
    type Prediccion,
    type Punto,
    type Ruta,
} from '@/components/google-maps';
import InputError from '@/components/input-error';
import { SelectorDeCadete, type CadeteElegible } from '@/components/selector-de-cadete';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Inicio — segunda columna: cargar un pedido.
|
| Es el «Alta de Pedido» del panel viejo, con sus mismos campos y en el mismo orden. Lo que
| la pantalla hace de verdad es tres cosas encadenadas: encontrar el comercio, armar las dos
| direcciones, y —cuando las dos salieron de Google— medir el viaje y poner el precio.
|
| ⚠️ **Un comercio también carga pedidos acá, y ve menos.** No elige a nombre de quién —es
| él—, no pone precio, no elige cadete y no tiene ni autocompletado ni mapa: en el panel
| viejo toda la sección de JavaScript está dentro de un `@if(tieneRol(['admin','empleado']))`,
| así que un `restringido` escribe las direcciones a mano y nada más. Medido: 19.431 pedidos
| cargados por una cuenta de comercio, 310 en los últimos 180 días.
|
| ⚠️ **El autocompletado de Google casi no se usa, y se porta igual porque así está hoy.**
| Medido sobre los 43.318 pedidos web de 180 días: la dirección tiene formato de Places en
| 772 casos, el destino en 1.021, y **las dos a la vez en 33 —el 0,1%—**, que son las únicas
| en las que el precio se calcula solo. Lo que la oficina escribe es «av alem 199», y el
| precio lo tipea: 3000 ocho mil veces, 2500 cuatro mil quinientas.
*/

/** Los mismos límites que el panel viejo le pasa a Places: el Gran San Miguel de Tucumán. */
const TUCUMAN = {
    suroeste: { lat: -26.93, lng: -65.37 },
    noreste: { lat: -26.71, lng: -64.98 },
};

/** Lo que tarda en preguntar después de la última tecla. */
const RESPIRO = 300;

/**
 * Lo que tarda una lista de sugerencias en cerrarse al salir del campo.
 *
 * ⚠️ **No es un detalle de estilo: sin esto la lista tapa el campo siguiente.** La de
 * «Dirección» queda abierta encima de «Destino», así que el clic con el que se va a escribir
 * el destino elige una sugerencia de la dirección. El panel viejo cierra las dos listas en
 * el `focusout` por este mismo motivo. La espera es para que el clic sobre una opción llegue
 * antes de que la opción desaparezca.
 */
const SE_CIERRA_EN = 400;

export type ParaImprimir = { id: number; numero: number | null };

export type ConfigDelMapa = { centro: Punto; zoom: number; clave: string | null };

type Comercio = {
    id: number;
    numero: number;
    nombre: string;
    direccion: string;
    telefono: string;
    responsable: string | null;
};

/**
 * La dirección como la arma el panel viejo con las partes que da Places: «calle altura,
 * ciudad, provincia».
 */
function comoSeEscribe(partes: ParteDeDireccion[]): string {
    let calle = '';
    let ciudad = '';
    let provincia = '';

    partes.forEach((parte) => {
        if (parte.types.includes('street_number')) {
            calle += parte.long_name;
        }

        if (parte.types.includes('route')) {
            calle = parte.long_name + ' ' + calle;
        }

        if (parte.types.includes('administrative_area_level_1')) {
            provincia = parte.long_name;
        }

        if (parte.types.includes('locality')) {
            ciudad = parte.long_name;
        }
    });

    return `${calle}, ${ciudad}, ${provincia}`;
}

export function NuevoPedido({
    deLaOficina,
    cola,
    mapa,
    ultimoPedido,
    cadeteElegido,
    alElegirCadete,
}: {
    /** Admin o empleado: pone precio, elige cadete y tiene las herramientas de Google. */
    deLaOficina: boolean;
    cola: CadeteElegible[] | null;
    mapa: ConfigDelMapa | null;
    /** El que se acaba de cargar, para poder imprimir el comprobante. */
    ultimoPedido: ParaImprimir | null;
    cadeteElegido: number | null;
    alElegirCadete: (id: number | null) => void;
}) {
    const [comercio, setComercio] = useState<Comercio | null>(null);
    const [numero, setNumero] = useState('');
    const [nombre, setNombre] = useState('');
    const [direccion, setDireccion] = useState('');
    const [destino, setDestino] = useState('');
    const [responsable, setResponsable] = useState('');
    const [telefono, setTelefono] = useState('');
    const [garantia, setGarantia] = useState('');
    const [valor, setValor] = useState('');
    const [retornoOrigen, setRetornoOrigen] = useState(false);

    // Los `place_id` de Google: sólo con los dos se puede medir el viaje y cotizarlo.
    const [lugarOrigen, setLugarOrigen] = useState<string | null>(null);
    const [lugarDestino, setLugarDestino] = useState<string | null>(null);

    const [ruta, setRuta] = useState<Ruta | null>(null);
    const [cotizando, setCotizando] = useState(false);
    const [problema, setProblema] = useState<string | null>(null);

    const { listo, api } = useApiDeGoogleMaps(deLaOficina ? (mapa?.clave ?? null) : null);

    const limpiar = () => {
        setComercio(null);
        setNumero('');
        setNombre('');
        setDireccion('');
        setDestino('');
        setResponsable('');
        setTelefono('');
        setGarantia('');
        setValor('');
        setRetornoOrigen(false);
        setLugarOrigen(null);
        setLugarDestino(null);
        setRuta(null);
        alElegirCadete(null);
    };

    /** Completa los campos con el comercio elegido, como `cargarClienteByNumero`. */
    const ponerElComercio = (elegido: Comercio) => {
        setComercio(elegido);
        setNumero(String(elegido.numero));
        setNombre(elegido.nombre);
        setDireccion(elegido.direccion);
        setTelefono(elegido.telefono);
        setResponsable(elegido.responsable ?? '');
        // La dirección vino del padrón y no de Google: no hay lugar con el que medir.
        setLugarOrigen(null);
        setRuta(null);
    };

    /*
    | Medir el viaje y preguntar cuánto sale.
    |
    | Sólo con las dos direcciones salidas de Google, como el `cotizarPedido()` viejo: sin
    | los dos `place_id` no hay distancia que medir. La tarifa la calcula el servidor —son
    | seis configuraciones de la empresa y no viajan al navegador—, y el precio queda en un
    | campo que el operador puede reescribir.
    */
    const cotizar = useCallback(() => {
        if (!listo || api === undefined || lugarOrigen === null || lugarDestino === null) {
            return;
        }

        setCotizando(true);
        setProblema(null);

        const pedido = {
            origin: { placeId: lugarOrigen },
            destination: { placeId: lugarDestino },
            travelMode: api.maps.TravelMode.DRIVING,
        };

        new api.maps.DirectionsService().route(pedido, (resultado, estado) => {
            const metros = resultado?.routes[0]?.legs[0]?.distance?.value;

            if (estado !== 'OK' || metros === undefined) {
                setCotizando(false);
                setProblema('Google no pudo medir el viaje entre esas dos direcciones.');

                return;
            }

            setRuta(resultado);

            const consulta = new URLSearchParams({
                distancia: String(metros),
                retorno_origen: retornoOrigen ? '1' : '0',
            });

            if (garantia !== '') {
                consulta.set('garantia', garantia);
            }

            void fetch(`/panel/tarifa?${consulta.toString()}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then((datos: { costo: string }) => setValor(datos.costo))
                .catch(() =>
                    setProblema('No se pudo calcular el precio. Se puede escribir a mano.'),
                )
                .finally(() => setCotizando(false));
        });
    }, [api, listo, lugarOrigen, lugarDestino, retornoOrigen, garantia]);

    // Cuando están los dos lugares, se cotiza. Y se vuelve a cotizar si cambia el retorno o
    // la garantía, que es lo que hacen los dos `change` del panel viejo.
    useEffect(() => {
        if (lugarOrigen === null || lugarDestino === null) {
            return;
        }

        const espera = setTimeout(cotizar, RESPIRO);

        return () => clearTimeout(espera);
    }, [cotizar, lugarOrigen, lugarDestino]);

    return (
        <Card className="gap-0 overflow-hidden p-0">
            <div className="bg-muted/50 flex items-center justify-between gap-2 border-b px-3 py-2">
                <span className="text-sm font-medium">Nuevo pedido</span>

                <div className="flex items-center gap-1">
                    {ultimoPedido !== null && (
                        <Button asChild size="sm" variant="secondary" className="h-7">
                            {/* Pestaña nueva, como el panel viejo: la página se imprime
                                sola al abrirse y después se cierra. */}
                            <a
                                href={`/panel/pedidos/${ultimoPedido.id}/cupon`}
                                target="_blank"
                                rel="noopener"
                            >
                                <Printer className="size-3.5" />
                                Imprimir {ultimoPedido.numero ?? ''}
                            </a>
                        </Button>
                    )}

                    {deLaOficina && (
                        <>
                            <ElMapa mapa={mapa} ruta={ruta} />
                            <NuevoComercio alCrear={ponerElComercio} />
                        </>
                    )}
                </div>
            </div>

            <Form
                action="/panel/pedidos"
                method="post"
                options={{ preserveScroll: true }}
                onSuccess={limpiar}
                className="p-3"
            >
                {({ errors, processing }) => (
                    <div className="grid gap-3">
                        {deLaOficina ? (
                            <ElComercio
                                comercio={comercio}
                                numero={numero}
                                setNumero={setNumero}
                                nombre={nombre}
                                setNombre={setNombre}
                                ponerElComercio={ponerElComercio}
                                errors={errors}
                            />
                        ) : (
                            // Un comercio no elige: el pedido es suyo y lo pone el servidor.
                            <p className="text-muted-foreground text-xs">
                                El pedido se carga a nombre de tu comercio.
                            </p>
                        )}

                        <DosLineas>
                            <DireccionConLugar
                                id="direccion"
                                titulo="Dirección"
                                nombre="direccion"
                                valor={direccion}
                                setValor={setDireccion}
                                lugar={lugarOrigen}
                                setLugar={setLugarOrigen}
                                conGoogle={deLaOficina && listo}
                                api={api}
                                alCambiar={() => {
                                    // El precio que había era de otro viaje. Es lo que hace
                                    // el panel viejo en cada tecla de estos dos campos.
                                    setValor('');
                                    setRuta(null);
                                }}
                                error={errors.direccion}
                            />

                            <Campo
                                id="direccion_detalle"
                                titulo="Depto. / Oficina"
                                nombre="direccion_detalle"
                                maximo={10}
                                error={errors.direccion_detalle}
                            />
                        </DosLineas>

                        <DosLineas>
                            <DireccionConLugar
                                id="destino"
                                titulo="Destino"
                                nombre="destino"
                                valor={destino}
                                setValor={setDestino}
                                lugar={lugarDestino}
                                setLugar={setLugarDestino}
                                conGoogle={deLaOficina && listo}
                                api={api}
                                alCambiar={() => {
                                    setValor('');
                                    setRuta(null);
                                }}
                                error={errors.destino}
                            />

                            <Campo
                                id="destino_detalle"
                                titulo="Depto. / Oficina"
                                nombre="destino_detalle"
                                maximo={10}
                                error={errors.destino_detalle}
                            />
                        </DosLineas>

                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                name="retorno_origen"
                                value="1"
                                checked={retornoOrigen}
                                onCheckedChange={(marcado) =>
                                    setRetornoOrigen(marcado === true)
                                }
                            />
                            Vuelve al origen
                        </label>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Campo
                                id="responsable"
                                titulo="Nombre de responsable"
                                nombre="responsable"
                                valor={responsable}
                                setValor={setResponsable}
                                maximo={255}
                                error={errors.responsable}
                            />

                            <Campo
                                id="telefono"
                                titulo="Teléfono"
                                nombre="telefono"
                                tipo="tel"
                                valor={telefono}
                                setValor={setTelefono}
                                maximo={20}
                                error={errors.telefono}
                            />
                        </div>

                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">Detalle</span>
                            <textarea
                                id="detalle"
                                name="detalle"
                                rows={3}
                                maxLength={1000}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError message={errors.detalle} />
                        </label>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Campo
                                id="garantia"
                                titulo="Garantía"
                                nombre="garantia"
                                tipo="number"
                                valor={garantia}
                                setValor={setGarantia}
                                error={errors.garantia}
                                prefijo="$"
                            />

                            {deLaOficina && (
                                <Campo
                                    id="valor"
                                    titulo={cotizando ? 'Valor (calculando…)' : 'Valor'}
                                    nombre="valor"
                                    tipo="number"
                                    valor={valor}
                                    setValor={setValor}
                                    error={errors.valor}
                                    prefijo="$"
                                />
                            )}
                        </div>

                        {problema !== null && (
                            <p className="text-muted-foreground text-xs">{problema}</p>
                        )}

                        {deLaOficina && cola !== null && (
                            <label className="grid gap-1 text-sm" htmlFor="cadete_id">
                                <span className="text-muted-foreground">Cadete</span>
                                <SelectorDeCadete
                                    id="cadete_id"
                                    cola={cola}
                                    elegido={cadeteElegido}
                                    alElegir={alElegirCadete}
                                />
                                <InputError message={errors.cadete_id} />
                            </label>
                        )}

                        {/* Lo que no se tipea: de qué comercio es, cuando se eligió uno. */}
                        {comercio !== null && (
                            <input type="hidden" name="cliente_id" value={comercio.id} />
                        )}

                        <div>
                            <Button type="submit" disabled={processing}>
                                Enviar
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </Card>
    );
}

/** Las dos columnas del panel viejo: 8 y 4 de doce. */
function DosLineas({ children }: { children: React.ReactNode }) {
    return <div className="grid gap-3 sm:grid-cols-3">{children}</div>;
}

/**
 * El comercio: por número o por nombre, buscando contra el servidor.
 *
 * ⚠️ El panel viejo se trae los 11.057 clientes en cada carga de la pantalla —679 KB— y
 * filtra en el navegador con jQuery. Acá se busca en la base, que es donde están.
 *
 * ⚠️ Se vincula por el número, que es único. El sistema viejo tuvo un `getByNombre` con
 * `first()`: con dos comercios del mismo nombre, el pedido se le colgaba al primero que
 * devolviera la base. Ver DATA-8.
 */
function ElComercio({
    comercio,
    numero,
    setNumero,
    nombre,
    setNombre,
    ponerElComercio,
    errors,
}: {
    comercio: Comercio | null;
    numero: string;
    setNumero: (valor: string) => void;
    nombre: string;
    setNombre: (valor: string) => void;
    ponerElComercio: (comercio: Comercio) => void;
    errors: Record<string, string>;
}) {
    const [encontrados, setEncontrados] = useState<Comercio[]>([]);
    const [buscando, setBuscando] = useState(false);
    const escribiendo = useRef(false);

    useEffect(() => {
        if (!escribiendo.current || nombre.trim() === '') {
            setEncontrados([]);

            return;
        }

        setBuscando(true);

        const espera = setTimeout(() => {
            void fetch(`/panel/clientes/buscar?q=${encodeURIComponent(nombre)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : []))
                .then((datos: Comercio[]) => setEncontrados(datos))
                .catch(() => setEncontrados([]))
                .finally(() => setBuscando(false));
        }, RESPIRO);

        return () => clearTimeout(espera);
    }, [nombre]);

    /** Por número exacto, como el `onfocusout` del campo «Cliente» viejo. */
    const buscarPorNumero = () => {
        if (numero.trim() === '') {
            return;
        }

        void fetch(`/panel/clientes/buscar?q=${encodeURIComponent(numero)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : []))
            .then((datos: Comercio[]) => {
                const exacto = datos.find((c) => String(c.numero) === numero.trim());

                if (exacto !== undefined) {
                    escribiendo.current = false;
                    ponerElComercio(exacto);
                }
            })
            .catch(() => undefined);
    };

    const elegir = (elegido: Comercio) => {
        escribiendo.current = false;
        setEncontrados([]);
        ponerElComercio(elegido);
    };

    return (
        <div className="grid gap-3 sm:grid-cols-4">
            <label className="grid gap-1 text-sm">
                <span className="text-muted-foreground">Cliente</span>
                <Input
                    id="numero"
                    type="number"
                    min={1}
                    value={numero}
                    onChange={(e) => setNumero(e.target.value)}
                    onBlur={buscarPorNumero}
                    autoComplete="off"
                />
            </label>

            <label className="relative grid gap-1 text-sm sm:col-span-3">
                <span className="text-muted-foreground">
                    Nombre de cliente o empresa
                </span>
                <Input
                    id="cliente"
                    name="cliente"
                    value={nombre}
                    maxLength={255}
                    onChange={(e) => {
                        escribiendo.current = true;
                        setNombre(e.target.value);
                    }}
                    onBlur={() => setTimeout(() => setEncontrados([]), SE_CIERRA_EN)}
                    autoComplete="off"
                />
                <InputError message={errors.cliente} />

                {/* Que no haya resultados es información: si no, el campo parece colgado. */}
                {nombre.trim() !== '' &&
                    escribiendo.current &&
                    !buscando &&
                    encontrados.length === 0 && (
                        <span className="text-muted-foreground text-xs">
                            Ningún comercio con ese nombre. Se puede cargar igual: el
                            nombre queda escrito en el pedido.
                        </span>
                    )}

                {encontrados.length > 0 && (
                    <ul className="bg-popover absolute top-full right-0 left-0 z-20 mt-1 max-h-64 divide-y overflow-y-auto rounded-md border shadow-md">
                        {encontrados.map((elegible) => (
                            <li key={elegible.id}>
                                <button
                                    type="button"
                                    className="hover:bg-muted w-full px-3 py-2 text-left text-sm"
                                    onClick={() => elegir(elegible)}
                                >
                                    <span className="tabular-nums">
                                        {elegible.numero}
                                    </span>{' '}
                                    · {elegible.nombre}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {comercio !== null && (
                    <span className="text-muted-foreground text-xs">
                        Pedido a nombre del comercio {comercio.numero}.
                    </span>
                )}
            </label>
        </div>
    );
}

/** Una dirección con el autocompletado de Google, cuando lo hay. */
function DireccionConLugar({
    id,
    titulo,
    nombre,
    valor,
    setValor,
    lugar,
    setLugar,
    conGoogle,
    api,
    alCambiar,
    error,
}: {
    id: string;
    titulo: string;
    nombre: string;
    valor: string;
    setValor: (valor: string) => void;
    lugar: string | null;
    setLugar: (lugar: string | null) => void;
    conGoogle: boolean;
    api: ReturnType<typeof useApiDeGoogleMaps>['api'];
    alCambiar: () => void;
    error?: string;
}) {
    const [predicciones, setPredicciones] = useState<Prediccion[]>([]);
    const escribiendo = useRef(false);

    useEffect(() => {
        if (!conGoogle || api === undefined || !escribiendo.current) {
            return;
        }

        const escrito = valor.trim();

        if (escrito === '') {
            setPredicciones([]);

            return;
        }

        const espera = setTimeout(() => {
            new api.maps.places.AutocompleteService().getPlacePredictions(
                {
                    input: escrito,
                    componentRestrictions: { country: 'AR' },
                    types: ['geocode'],
                    locationBias: new api.maps.LatLngBounds(
                        new api.maps.LatLng(TUCUMAN.suroeste.lat, TUCUMAN.suroeste.lng),
                        new api.maps.LatLng(TUCUMAN.noreste.lat, TUCUMAN.noreste.lng),
                    ),
                },
                (encontradas, estado) => {
                    setPredicciones(
                        estado === api.maps.places.PlacesServiceStatus.OK
                            ? (encontradas ?? [])
                            : [],
                    );
                },
            );
        }, RESPIRO);

        return () => clearTimeout(espera);
    }, [valor, conGoogle, api]);

    /** Al elegir una, se pide la dirección en partes y se arma el texto. */
    const elegir = (prediccion: Prediccion) => {
        if (api === undefined) {
            return;
        }

        // PlacesService pide un elemento donde poner sus atribuciones; uno suelto sirve.
        new api.maps.places.PlacesService(document.createElement('div')).getDetails(
            { placeId: prediccion.place_id, fields: ['address_components'] },
            (lugarElegido, estado) => {
                if (
                    estado !== api.maps.places.PlacesServiceStatus.OK ||
                    lugarElegido?.address_components === undefined
                ) {
                    return;
                }

                escribiendo.current = false;
                setPredicciones([]);
                setValor(comoSeEscribe(lugarElegido.address_components));
                setLugar(prediccion.place_id);
            },
        );
    };

    return (
        <label className="relative grid gap-1 text-sm sm:col-span-2">
            <span className="text-muted-foreground">
                {titulo}
                {lugar !== null && (
                    <span className="text-muted-foreground/70">
                        {' '}
                        · ubicada en el mapa
                    </span>
                )}
            </span>

            <Input
                id={id}
                name={nombre}
                value={valor}
                maxLength={255}
                autoComplete="off"
                onChange={(e) => {
                    escribiendo.current = true;
                    setValor(e.target.value);
                    // Lo escrito a mano ya no es el lugar que había elegido.
                    setLugar(null);
                    alCambiar();
                }}
                onKeyDown={(e) => {
                    // El panel viejo también lo frena: Enter acá enviaría el formulario.
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                }}
                onBlur={() => {
                    escribiendo.current = false;
                    setTimeout(() => setPredicciones([]), SE_CIERRA_EN);
                }}
            />
            <InputError message={error} />

            {predicciones.length > 0 && (
                <ul className="bg-popover absolute top-full right-0 left-0 z-20 mt-1 max-h-64 divide-y overflow-y-auto rounded-md border shadow-md">
                    {predicciones.map((prediccion) => (
                        <li key={prediccion.place_id}>
                            <button
                                type="button"
                                className="hover:bg-muted w-full px-3 py-2 text-left text-sm"
                                onClick={() => elegir(prediccion)}
                            >
                                {prediccion.description}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </label>
    );
}

/** Un campo de texto del formulario, con su error debajo. */
function Campo({
    id,
    titulo,
    nombre,
    tipo = 'text',
    valor,
    setValor,
    maximo,
    error,
    prefijo,
}: {
    id: string;
    titulo: string;
    nombre: string;
    tipo?: string;
    valor?: string;
    setValor?: (valor: string) => void;
    maximo?: number;
    error?: string;
    prefijo?: string;
}) {
    return (
        <label className="grid gap-1 text-sm" htmlFor={id}>
            <span className="text-muted-foreground">{titulo}</span>

            <div className="flex items-center gap-1">
                {prefijo !== undefined && (
                    <span className="text-muted-foreground text-sm">{prefijo}</span>
                )}
                <Input
                    id={id}
                    name={nombre}
                    type={tipo}
                    maxLength={maximo}
                    step={tipo === 'number' ? '0.01' : undefined}
                    min={tipo === 'number' ? 0 : undefined}
                    value={valor}
                    onChange={
                        setValor === undefined
                            ? undefined
                            : (e) => setValor(e.target.value)
                    }
                    autoComplete="off"
                />
            </div>

            <InputError message={error} />
        </label>
    );
}

/**
 * El mapa del alta: el viaje dibujado, cuando hay uno.
 *
 * Es el `modalMapaPedido` del panel viejo. Sólo tiene algo que mostrar cuando las dos
 * direcciones salieron de Google; si no, muestra la ciudad.
 */
function ElMapa({ mapa, ruta }: { mapa: ConfigDelMapa | null; ruta: Ruta | null }) {
    const [abierto, setAbierto] = useState(false);

    return (
        <Dialog open={abierto} onOpenChange={setAbierto}>
            <DialogTrigger asChild>
                <Button size="sm" variant="secondary" className="h-7">
                    <Map className="size-3.5" />
                    Mapa
                </Button>
            </DialogTrigger>

            <DialogContent className="sm:max-w-[57.6rem]">
                <DialogHeader>
                    <DialogTitle>Mapa</DialogTitle>
                    <DialogDescription>
                        {ruta === null
                            ? 'El viaje se dibuja cuando las dos direcciones salen del autocompletado de Google.'
                            : 'El viaje entre las dos direcciones.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="bg-muted relative h-[420px] overflow-hidden rounded-md">
                    {/* El mapa se crea recién cuando el diálogo está abierto: uno creado
                        dentro de algo oculto nace con tamaño cero. */}
                    {abierto && <ElViaje mapa={mapa} ruta={ruta} />}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ElViaje({ mapa, ruta }: { mapa: ConfigDelMapa | null; ruta: Ruta | null }) {
    const division = useRef<HTMLDivElement>(null);
    const deGoogle = useRef<MapaDeGoogle | null>(null);
    const { listo, falló, api } = useApiDeGoogleMaps(mapa?.clave ?? null);

    useEffect(() => {
        if (!listo || api === undefined || division.current === null || mapa === null) {
            return;
        }

        if (deGoogle.current === null) {
            deGoogle.current = new api.maps.Map(division.current, {
                zoom: mapa.zoom,
                center: mapa.centro,
                mapTypeControl: false,
            });
        }

        if (ruta !== null) {
            // El renderizador encuadra el viaje solo, así que no hace falta calcular
            // los límites como hacía el panel viejo.
            new api.maps.DirectionsRenderer({ map: deGoogle.current }).setDirections(ruta);
        }
    }, [listo, api, mapa, ruta]);

    if (mapa === null || mapa.clave === null || mapa.clave === '') {
        return <FaltaLaClave />;
    }

    if (falló) {
        return (
            <div className="text-muted-foreground flex h-full items-center justify-center p-6 text-center text-sm">
                No se pudo cargar el mapa de Google. Puede ser la conexión, o que la clave
                no tenga habilitado este dominio.
            </div>
        );
    }

    return (
        <div
            ref={division}
            className="h-full w-full"
            role="application"
            aria-label="El viaje del pedido"
        />
    );
}

/**
 * Dar de alta un comercio sin salir de acá.
 *
 * ⚠️ Existe por un motivo concreto y es el que dice su nombre en el panel viejo: si para
 * cargar un comercio nuevo hay que irse de la pantalla, el pedido que se estaba tipeando se
 * pierde. Al guardar, completa los campos del alta con el comercio recién creado.
 *
 * ⚠️ El número no se pide: lo asigna la secuencia de la base. El modal viejo lo precargaba
 * con una sugerencia que el empleado podía reescribir, y entonces la secuencia no avanzaba.
 */
function NuevoComercio({ alCrear }: { alCrear: (comercio: Comercio) => void }) {
    const [abierto, setAbierto] = useState(false);
    const [errores, setErrores] = useState<Record<string, string[]>>({});
    const [guardando, setGuardando] = useState(false);

    const guardar = (evento: React.FormEvent<HTMLFormElement>) => {
        evento.preventDefault();
        setGuardando(true);
        setErrores({});

        const formulario = evento.currentTarget;

        void fetch('/panel/clientes/rapido', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(formulario),
        })
            .then(async (respuesta) => {
                const datos = await respuesta.json();

                if (respuesta.status === 422) {
                    setErrores(datos.errors ?? {});

                    return;
                }

                if (!respuesta.ok) {
                    throw new Error(String(respuesta.status));
                }

                formulario.reset();
                setAbierto(false);
                alCrear(datos as Comercio);
            })
            .catch(() =>
                setErrores({ nombre: ['No se pudo crear el comercio. Probá de nuevo.'] }),
            )
            .finally(() => setGuardando(false));
    };

    return (
        <Dialog open={abierto} onOpenChange={setAbierto}>
            <DialogTrigger asChild>
                <Button size="sm" variant="secondary" className="h-7">
                    <UserPlus className="size-3.5" />
                    Nuevo cliente
                </Button>
            </DialogTrigger>

            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Nuevo cliente</DialogTitle>
                    <DialogDescription>
                        El número se lo asigna el sistema al guardar. Queda cargado en el
                        pedido que estás escribiendo.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={guardar} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="rapido-nombre">Nombre</Label>
                        <Input id="rapido-nombre" name="nombre" required maxLength={255} />
                        <InputError message={errores.nombre?.[0]} />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="rapido-direccion">Dirección</Label>
                        <Input
                            id="rapido-direccion"
                            name="direccion"
                            required
                            maxLength={255}
                        />
                        <InputError message={errores.direccion?.[0]} />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="rapido-telefono">Teléfono</Label>
                        <Input
                            id="rapido-telefono"
                            name="telefono"
                            type="tel"
                            required
                            maxLength={20}
                        />
                        <InputError message={errores.telefono?.[0]} />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="rapido-responsable">
                            Nombre del empleado (opcional)
                        </Label>
                        <Input
                            id="rapido-responsable"
                            name="nombre_empleado"
                            maxLength={255}
                        />
                        <InputError message={errores.nombre_empleado?.[0]} />
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={guardando}>
                            Guardar
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
