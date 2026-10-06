import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowDown, Info, MessageSquare, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { MapaDeCadetes, type EnElMapa, type Punto } from '@/components/mapa-de-cadetes';
import {
    ListaDeEspera,
    type EnEspera,
    type ParaAsignar,
} from '@/pages/inicio/lista-de-espera';
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
| de doce—, porque es la pantalla que la gente tiene memorizada.
|
| ⚠️ SE ESTÁ PORTANDO POR PARTES. Hechas la primera y la tercera; la del medio dice que falta
| en vez de no estar, así que la pantalla es la de verdad en cada paso.
|
| ⚠️ La primera y la tercera columna son de oficina. Un comercio entra acá sólo por la del
| medio, y entonces no se le dibuja un hueco donde no va nada: se le da el ancho a lo que sí
| puede usar.
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
    refresco,
}: {
    cola: EnLaCola[] | null;
    porQueNoLimpiar: string | null;
    espera: EnEspera[] | null;
    paraAsignar: ParaAsignar[] | null;
    mapa: ConfigDelMapa | null;
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

    usePoll(refresco, {
        only: ['cola', 'porQueNoLimpiar', 'espera', 'paraAsignar'],
    });

    return (
        <>
            <Head title="Inicio" />

            <div className="grid flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-12">
                {cola !== null && (
                    <div className="lg:col-span-2">
                        <LaCola
                            cola={cola}
                            porQueNoLimpiar={porQueNoLimpiar}
                            verDetalle={verDetalle}
                        />
                    </div>
                )}

                <div className={cola === null ? 'lg:col-span-7' : 'lg:col-span-5'}>
                    <Pendiente
                        titulo="Nuevo pedido"
                        detalle="El formulario de carga. Es la segunda parte de la pantalla."
                    />
                </div>

                {espera !== null && (
                    <div className="lg:col-span-5">
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
        </>
    );
}

function LaCola({
    cola,
    porQueNoLimpiar,
    verDetalle,
}: {
    cola: EnLaCola[];
    porQueNoLimpiar: string | null;
    verDetalle: (id: number) => void;
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
                        <th className="px-2 py-1.5 font-medium">Móvil</th>
                        <th className="px-2 py-1.5 font-medium">Vehículo</th>
                    </tr>
                </thead>
                <tbody>
                    {cola.map((cadete) => (
                        <Fila
                            key={cadete.id}
                            cadete={cadete}
                            verDetalle={verDetalle}
                        />
                    ))}

                    {cola.length === 0 && (
                        <tr>
                            <td
                                colSpan={2}
                                className="text-muted-foreground px-2 py-6 text-center"
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
}: {
    cadete: EnLaCola;
    verDetalle: (id: number) => void;
}) {
    return (
        <tr className="hover:bg-muted/40 border-t">
            <td className="px-2 py-1.5 font-medium tabular-nums">{cadete.movil}</td>
            <td className="px-2 py-1.5">
                <div className="flex items-center justify-between gap-1">
                    {/* Acortado, con el nombre entero al pasar el mouse: la columna tiene
                        dos doceavos y el nombre completo echa los botones afuera. */}
                    <span
                        className="text-muted-foreground"
                        title={cadete.vehiculo_entero}
                    >
                        {cadete.vehiculo}
                    </span>

                    <div className="flex items-center gap-0.5">
                        <Badge
                            variant={cadete.en_la_app ? 'default' : 'secondary'}
                            className="mr-0.5 px-1 py-0 text-[10px]"
                        >
                            {cadete.en_la_app ? 'APP' : 'WEB'}
                        </Badge>

                        <AlFinal cadete={cadete} />

                        <Button asChild size="icon" variant="ghost" className="size-5">
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
                            className="size-5"
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
                    className="size-5"
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

function Pendiente({ titulo, detalle }: { titulo: string; detalle: string }) {
    return (
        <Card className="text-muted-foreground h-full items-center justify-center border-dashed p-6 text-center text-sm">
            <span className="text-foreground font-medium">{titulo}</span>
            <span>{detalle}</span>
        </Card>
    );
}

InicioIndex.layout = {
    breadcrumbs: [{ title: 'Inicio', href: '/panel' }],
};
