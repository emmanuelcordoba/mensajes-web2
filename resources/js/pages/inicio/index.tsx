import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowDown, Info, MessageSquare, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { MapaDeCadetes, type EnElMapa, type Punto } from '@/components/mapa-de-cadetes';
import {
    ListaDeEspera,
    type EnEspera,
    type ParaAsignar,
} from '@/pages/inicio/lista-de-espera';
import {
    NuevoPedido,
    type ParaImprimir,
} from '@/pages/inicio/nuevo-pedido';
import { ProveedorDePausaDeRefresco } from '@/hooks/use-pausa-de-refresco';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/*
| Inicio — la pantalla que la oficina mira todo el día.
|
| Tres columnas, las mismas tres del panel viejo y en el mismo orden: la cola de cadetes, la
| carga de un pedido y la lista de espera. Las proporciones también son las de allá —2, 5 y 5
| de doce—, porque es la pantalla que la gente tiene memorizada. Medido en el panel viejo a
| 1360 px, que es la pantalla del cliente: 183, 457 y 457 sobre 1097 px de fila.
|
| ⚠️ **El `min-w-0` de cada columna no es adorno.** Un hijo de grilla trae `min-width: auto`,
| así que un contenido que no se puede encoger —una tabla con `nowrap`, un campo con su
| etiqueta— agranda su columna y se lo saca a las otras dos. Con `min-w-0` las tres valen lo
| que dice la grilla, y la que no entra la recorta quien no entra y no el vecino.
|
| ⚠️ La primera y la tercera columna son de oficina. Un comercio entra acá sólo por la del
| medio, y entonces no se le dibuja un hueco donde no va nada: se le da el ancho a lo que sí
| puede usar.
|
| ⚠️ Las columnas se hablan entre sí, y eso es del panel viejo: el doble clic en un cadete de
| la cola lo elige en el formulario del medio, que es cómo la central asigna sin buscar en el
| selector. Por eso cuál está elegido vive acá y no adentro del formulario.
*/

type EnLaCola = {
    id: number;
    movil: number;
    vehiculo: string;
    vehiculo_entero: string;
    en_la_app: boolean;
};

type Detalle = {
    id: number;
    movil: number;
    apellidos: string | null;
    nombres: string | null;
    email: string | null;
    direccion: string | null;
    telefono: string | null;
    nacimiento: string | null;
    vehiculo: string;
    estado: string;
    posicion: number | null;
    /** Sólo si está en la app y tiene posición, como en el panel viejo. */
    ubicacion: EnElMapa | null;
    por_que_no_al_final: string | null;
    por_que_no_sacar: string | null;
};

type ConfigDelMapa = { centro: Punto; zoom: number; clave: string | null };

export default function InicioIndex({
    cola,
    porQueNoLimpiar,
    espera,
    paraAsignar,
    mapa,
    deLaOficina,
    ultimoPedido,
    refresco,
}: {
    cola: EnLaCola[] | null;
    porQueNoLimpiar: string | null;
    espera: EnEspera[] | null;
    paraAsignar: ParaAsignar[] | null;
    mapa: ConfigDelMapa | null;
    deLaOficina: boolean;
    ultimoPedido: ParaImprimir | null;
    refresco: number;
}) {
    /*
    | El detalle del cadete que alguien abrió.
    |
    | ⚠️ VIVE ACÁ Y NO EN LAS PROPS, y no es un detalle de implementación: la primera versión
    | lo pedía con un refresco parcial de Inertia pasándole `?cadete=302`, y el parámetro
    | quedaba en la URL al cerrar el diálogo. Volver a abrir el mismo cadete pedía lo mismo,
    | llegaba la misma prop, React no veía ningún cambio y el diálogo no se abría. Qué
    | diálogo está abierto es estado de esta pantalla, no de la dirección.
    |
    | Se pide con un `fetch` a un endpoint que devuelve JSON, que es lo que hacía el panel
    | viejo con `ajax/cadetes/getcadete`, y por la misma razón que entonces: son el nombre,
    | el teléfono y la dirección de una persona, y no tienen por qué viajar en cada refresco
    | de una pantalla que está abierta todo el día en una oficina.
    */
    const [abierto, setAbierto] = useState(false);
    const [detalle, setDetalle] = useState<Detalle | null>(null);
    const [error, setError] = useState<string | null>(null);

    /*
    | A qué cadete va el pedido que se está cargando.
    |
    | Vive en la pantalla y no en el formulario porque lo eligen dos lugares: el selector
    | del formulario y el doble clic en una fila de la cola, que es el `seleccionarCadete()`
    | del panel viejo. La fila elegida se marca, como allá.
    */
    const [cadeteElegido, setCadeteElegido] = useState<number | null>(null);

    const verDetalle = async (id: number) => {
        setAbierto(true);
        setDetalle(null);
        setError(null);

        try {
            const respuesta = await fetch(`/panel/cadetes/${id}/detalle`, {
                headers: { Accept: 'application/json' },
            });

            if (!respuesta.ok) {
                throw new Error(String(respuesta.status));
            }

            setDetalle((await respuesta.json()) as Detalle);
        } catch {
            setError('No se pudo cargar el cadete. Probá de nuevo.');
        }
    };

    /*
    | ⚠️ `stop` y `start` no son para apagar el refresco: son para pausarlo mientras alguien
    | tiene desplegada una lista de cadetes, en el alta o en el modal de la lista de espera.
    | El porqué está en el hook.
    */
    const { stop, start } = usePoll(refresco, {
        only: ['cola', 'porQueNoLimpiar', 'espera', 'paraAsignar'],
    });

    return (
        <ProveedorDePausaDeRefresco parar={stop} seguir={start}>
            <Head title="Inicio" />

            {/* ⚠️ `gap-2` y no `gap-4`: once separaciones de 16 px se comen 176 px de una
                fila de 1081, y eso es lo que dejaba a «Cadetes activos» con 162 px donde el
                panel viejo le daba 183 y su tabla pide 170. Allá las tres tarjetas casi se
                tocaban —`px-0`, `px-1`, `px-0`—, así que 8 px es además lo que se parece a
                la pantalla que la gente tiene memorizada. */}
            <div className="grid flex-1 grid-cols-1 gap-2 p-4 lg:grid-cols-12">
                {cola !== null && (
                    <div className="min-w-0 lg:col-span-2">
                        <LaCola
                            cola={cola}
                            porQueNoLimpiar={porQueNoLimpiar}
                            verDetalle={verDetalle}
                            elegido={cadeteElegido}
                            elegir={setCadeteElegido}
                        />
                    </div>
                )}

                <div
                    className={`min-w-0 ${cola === null ? 'lg:col-span-7' : 'lg:col-span-5'}`}
                >
                    <NuevoPedido
                        deLaOficina={deLaOficina}
                        cadetes={paraAsignar}
                        mapa={mapa}
                        ultimoPedido={ultimoPedido}
                        cadeteElegido={cadeteElegido}
                        alElegirCadete={setCadeteElegido}
                    />
                </div>

                {espera !== null && (
                    <div className="min-w-0 lg:col-span-5">
                        <ListaDeEspera
                            espera={espera}
                            paraAsignar={paraAsignar ?? []}
                        />
                    </div>
                )}
            </div>

            <ElDetalle
                abierto={abierto}
                cerrar={() => setAbierto(false)}
                detalle={detalle}
                error={error}
                mapa={mapa}
            />
        </ProveedorDePausaDeRefresco>
    );
}

function LaCola({
    cola,
    porQueNoLimpiar,
    verDetalle,
    elegido,
    elegir,
}: {
    cola: EnLaCola[];
    porQueNoLimpiar: string | null;
    verDetalle: (id: number) => void;
    elegido: number | null;
    elegir: (id: number | null) => void;
}) {
    return (
        <Card className="gap-0 overflow-hidden p-0">
            <div className="bg-muted/50 flex items-center justify-between gap-2 border-b px-3 py-2">
                <span className="text-sm font-medium">
                    Cadetes activos{' '}
                    <span className="text-muted-foreground tabular-nums">
                        ({cola.length})
                    </span>
                </span>

                {/* El botón no está cuando no hay a quién sacar, que es lo que hacía el
                    panel viejo con su `@if(count($cadetes_activos)>0)`. La misma función
                    que lo esconde acá es la que el controlador consulta antes de escribir. */}
                {porQueNoLimpiar === null && <Limpiar cuantos={cola.length} />}
            </div>

            <table className="w-full text-sm">
                <thead className="text-muted-foreground text-left">
                    <tr>
                        <th className="px-1 py-1.5 font-medium">Móvil</th>
                        <th className="px-1 py-1.5 font-medium">Veh.</th>
                    </tr>
                </thead>
                <tbody>
                    {cola.map((cadete) => (
                        <Fila
                            key={cadete.id}
                            cadete={cadete}
                            verDetalle={verDetalle}
                            elegido={cadete.id === elegido}
                            elegir={elegir}
                        />
                    ))}

                    {cola.length === 0 && (
                        <tr>
                            <td
                                colSpan={2}
                                className="text-muted-foreground px-1 py-6 text-center"
                            >
                                No hay nadie en la cola.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </Card>
    );
}

function Fila({
    cadete,
    verDetalle,
    elegido,
    elegir,
}: {
    cadete: EnLaCola;
    verDetalle: (id: number) => void;
    elegido: boolean;
    elegir: (id: number | null) => void;
}) {
    return (
        /* El doble clic elige el cadete para el pedido que se está cargando, como el
           `ondblclick` del panel viejo. Un doble clic no se descubre ni se alcanza con el
           teclado, así que la fila además es enfocable y la barra espaciadora hace lo mismo. */
        <tr
            className={`border-t ${elegido ? 'bg-accent' : 'hover:bg-muted/40'}`}
            tabIndex={0}
            aria-selected={elegido}
            title={`Doble clic para darle el pedido al móvil ${cadete.movil}`}
            onDoubleClick={() => elegir(elegido ? null : cadete.id)}
            onKeyDown={(evento) => {
                if (evento.key === ' ' || evento.key === 'Enter') {
                    evento.preventDefault();
                    elegir(elegido ? null : cadete.id);
                }
            }}
        >
            <td className="px-1 py-1.5 font-medium tabular-nums">{cadete.movil}</td>
            <td className="px-1 py-1.5">
                <div className="flex items-center justify-between gap-1">
                    {/* ⚠️ Una letra, con el nombre entero al pasar el mouse. La columna
                        tiene dos doceavos y hasta «Moto» echaba los botones afuera: medido
                        el 2026-11-09, la tabla pedía 204 px y a 1366 de pantalla la tarjeta
                        le daba 161 y recortaba sin dejar desplazarse. El `aria-label` va
                        porque una «M» sola no se puede leer en voz alta.

                        Los tres botones miden 16 y no 20 px por lo mismo: con el móvil más
                        largo que hay —100152, seis cifras— y la barra lateral desplegada,
                        la tabla pedía 182 px y la tarjeta daba 160. El ícono sigue midiendo
                        12, así que lo que se achicó es el aire alrededor. */}
                    <span
                        className="text-muted-foreground"
                        title={cadete.vehiculo_entero}
                        aria-label={cadete.vehiculo_entero}
                    >
                        {cadete.vehiculo}
                    </span>

                    {/* `flex-wrap` de red: si algún día no entran, bajan a un renglón en
                        lugar de desaparecer debajo del borde de la tarjeta. */}
                    <div className="flex flex-wrap items-center gap-px">
                        <Badge
                            variant={cadete.en_la_app ? 'default' : 'secondary'}
                            className="px-0.5 py-0 text-[10px]"
                        >
                            {cadete.en_la_app ? 'APP' : 'WEB'}
                        </Badge>

                        <AlFinal cadete={cadete} />

                        <Button asChild size="icon" variant="ghost" className="size-4">
                            <Link
                                href={`/panel/mensajes/${cadete.id}`}
                                title={`Chat con el móvil ${cadete.movil}`}
                            >
                                <MessageSquare className="size-3" />
                            </Link>
                        </Button>

                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-4"
                            title={`Detalle del móvil ${cadete.movil}`}
                            onClick={() => verDetalle(cadete.id)}
                        >
                            <Info className="size-3" />
                        </Button>
                    </div>
                </div>
            </td>
        </tr>
    );
}

function AlFinal({ cadete }: { cadete: EnLaCola }) {
    return (
        <Form
            action={`/panel/cadetes/cola/${cadete.id}/al-final`}
            method="post"
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(`El móvil ${cadete.movil} pasa al final de la cola.\n\n¿Seguir?`)
            }
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    size="icon"
                    variant="ghost"
                    className="size-4"
                    disabled={processing}
                    title={`Mandar el móvil ${cadete.movil} al final de la cola`}
                >
                    <ArrowDown className="size-3" />
                </Button>
            )}
        </Form>
    );
}

function Limpiar({ cuantos }: { cuantos: number }) {
    return (
        <Form
            action="/panel/cadetes/cola"
            method="delete"
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(
                    `Salen de la cola ${cuantos} ${cuantos === 1 ? 'cadete' : 'cadetes'} y quedan inactivos.\n\n` +
                        `Los que estén en la app tienen que volver a ponerse activos desde el teléfono.\n\n¿Seguir?`,
                )
            }
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    size="icon"
                    variant="destructive"
                    className="size-5"
                    disabled={processing}
                    title="Vaciar la cola"
                >
                    <Trash2 className="size-3" />
                </Button>
            )}
        </Form>
    );
}

/**
 * El detalle de un cadete: sus datos, dónde está y qué se le puede hacer.
 *
 * Es el `modalDetalleCadete` del panel viejo, con lo mismo adentro: los datos a la
 * izquierda, el mapa a la derecha, y los dos botones —mover al final y quitar—.
 */
function ElDetalle({
    abierto,
    cerrar,
    detalle,
    error,
    mapa,
}: {
    abierto: boolean;
    cerrar: () => void;
    detalle: Detalle | null;
    error: string | null;
    mapa: ConfigDelMapa | null;
}) {
    return (
        <Dialog open={abierto} onOpenChange={(abre) => !abre && cerrar()}>
            {/* Un 20% más grande que el `max-w-3xl` con el que nació: 48rem × 1,2 = 57,6rem,
                y el mapa acompaña para que las dos mitades sigan en proporción. */}
            <DialogContent className="sm:max-w-[57.6rem]">
                <DialogHeader>
                    <DialogTitle>
                        {detalle === null
                            ? 'Cadete'
                            : `Cadete móvil N° ${detalle.movil}`}
                    </DialogTitle>
                    <DialogDescription>
                        {detalle === null
                            ? 'Buscando los datos…'
                            : detalle.estado +
                              (detalle.posicion === null
                                  ? ''
                                  : ` · puesto ${detalle.posicion} en la cola`)}
                    </DialogDescription>
                </DialogHeader>

                {error !== null && (
                    <p className="text-destructive text-sm">{error}</p>
                )}

                {detalle !== null && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col justify-between gap-4">
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                <Dato titulo="Apellido" valor={detalle.apellidos} />
                                <Dato titulo="Nombres" valor={detalle.nombres} />
                                <Dato titulo="Email" valor={detalle.email} />
                                <Dato titulo="Dirección" valor={detalle.direccion} />
                                <Dato titulo="Teléfono" valor={detalle.telefono} />
                                <Dato titulo="Nacimiento" valor={detalle.nacimiento} />
                                <Dato titulo="Vehículo" valor={detalle.vehiculo} />
                            </dl>

                            <div className="flex flex-col gap-2">
                                <AccionDelDetalle
                                    accion={`/panel/cadetes/cola/${detalle.id}/al-final`}
                                    metodo="post"
                                    porQueNo={detalle.por_que_no_al_final}
                                    confirmacion={`El móvil ${detalle.movil} pasa al final de la cola.\n\n¿Seguir?`}
                                    alTerminar={cerrar}
                                    variante="secondary"
                                >
                                    <ArrowDown className="size-4" /> Mover al final
                                </AccionDelDetalle>

                                <AccionDelDetalle
                                    accion={`/panel/cadetes/cola/${detalle.id}`}
                                    metodo="delete"
                                    porQueNo={detalle.por_que_no_sacar}
                                    confirmacion={
                                        `El móvil ${detalle.movil} sale de la cola y queda inactivo.\n\n` +
                                        `Si está en la app, tiene que volver a ponerse activo desde el teléfono.\n\n¿Seguir?`
                                    }
                                    alTerminar={cerrar}
                                    variante="destructive"
                                >
                                    <Trash2 className="size-4" /> Quitar
                                </AccionDelDetalle>
                            </div>
                        </div>

                        <div className="bg-muted relative h-[384px] overflow-hidden rounded-md">
                            {/* ⚠️ Sólo si está en la app y tiene posición, como el panel
                                viejo: un cadete puesto en la cola desde la central no
                                manda su ubicación, y el mapa no tiene nada que mostrar. */}
                            {detalle.ubicacion === null || mapa === null ? (
                                <div className="text-muted-foreground flex h-full items-center justify-center p-6 text-center text-sm">
                                    Cadete sin ubicación
                                </div>
                            ) : (
                                <MapaDeCadetes
                                    cadetes={[detalle.ubicacion]}
                                    centro={{
                                        lat: detalle.ubicacion.lat,
                                        lng: detalle.ubicacion.lon,
                                    }}
                                    zoom={14}
                                    clave={mapa.clave}
                                    etiqueta={`Dónde está el móvil ${detalle.movil}`}
                                    className="h-full w-full"
                                />
                            )}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Un botón del detalle, o el motivo por el que no está.
 *
 * El motivo se muestra entero en lugar de un botón que iba a fallar: es la misma decisión
 * que el listado de cadetes, y la misma función que acá decide es la que el controlador
 * consulta antes de escribir.
 */
function AccionDelDetalle({
    accion,
    metodo,
    porQueNo,
    confirmacion,
    alTerminar,
    variante,
    children,
}: {
    accion: string;
    metodo: 'post' | 'delete';
    porQueNo: string | null;
    confirmacion: string;
    alTerminar: () => void;
    variante: 'secondary' | 'destructive';
    children: React.ReactNode;
}) {
    if (porQueNo !== null) {
        return (
            <span className="text-muted-foreground text-xs">{porQueNo}</span>
        );
    }

    return (
        <Form
            action={accion}
            method={metodo}
            options={{ preserveScroll: true }}
            onBefore={() => confirm(confirmacion)}
            onSuccess={alTerminar}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant={variante}
                    disabled={processing}
                    className="w-full"
                >
                    {children}
                </Button>
            )}
        </Form>
    );
}

function Dato({ titulo, valor }: { titulo: string; valor?: string | null }) {
    return (
        <>
            <dt className="text-muted-foreground">{titulo}</dt>
            <dd>
                {valor === null || valor === undefined || valor === '' ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    valor
                )}
            </dd>
        </>
    );
}

InicioIndex.layout = {
    breadcrumbs: [{ title: 'Inicio', href: '/panel' }],
};
