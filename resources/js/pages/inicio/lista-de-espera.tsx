/*
| La lista de espera y su modal, que son la tercera columna de Inicio.
|
| ⚠️ El color de la fila dice el estado, como el panel viejo: lo que la oficina mira de reojo
| es si un pedido se canceló o lo rechazaron, y eso tiene que verse sin leer.
|
| ⚠️ El doble clic abre el modal. Es como se usa hoy y la gente lo tiene en los dedos; va
| además un botón, porque un doble clic no se descubre ni se alcanza con el teclado.
*/

import { Form, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Quién cargó el pedido, en dos letras sobre su color.
 *
 * ⚠️ No es decoración: la lista la miran varias personas a la vez y el distintivo es lo que
 * le deja a cada una ver cuáles son sus pedidos sin leer nada. Null cuando esa cuenta no
 * tiene iniciales o no tiene color; hacen falta las dos. Ver `User::distintivo()`.
 */
export type Distintivo = {
    nombre: string;
    iniciales: string;
    color: string;
    contraste: string | null;
};

export type EnEspera = {
    id: number;
    numero: number | null;
    numero_corto: string | null;
    cliente: string | null;
    direccion: string | null;
    destino: string | null;
    estado: string;
    plataforma: string;
    distintivo: Distintivo | null;
};

export type ParaAsignar = {
    id: number;
    movil: number;
    nombre: string;
    en_la_app: boolean;
};

type DetalleDePedido = {
    id: number;
    numero: number | null;
    estado: string;
    plataforma: string;
    cliente_numero: number | null;
    cliente: string | null;
    cargado_por: string | null;
    distintivo: Distintivo | null;
    direccion: string | null;
    destino: string | null;
    responsable: string | null;
    telefono: string | null;
    detalle: string | null;
    valor: string | null;
    garantia: string | null;
    retorno_origen: boolean;
    tipo_paquete: string | null;
    peso_paquete: string | null;
    valor_declarado: string | null;
    editable: boolean;
    cadete_id: number | null;
};

/*
| Los mismos seis colores del panel viejo, traducidos a las clases de acá. Lo que importa de
| cada uno es que se distingan entre sí de un vistazo, no cuál es.
*/
const COLOR_DE_ESTADO: Record<string, string> = {
    'Sin asignar': 'bg-muted/40',
    Asignado: 'bg-slate-500/15',
    'En curso': 'bg-blue-500/15',
    Rechazado: 'bg-amber-500/20',
    Cancelado: 'bg-red-500/20',
    Finalizado: 'bg-green-500/15',
};

const COLOR_DE_PLATAFORMA: Record<string, string> = {
    web: 'bg-secondary text-secondary-foreground',
    app: 'bg-primary text-primary-foreground',
    api: 'bg-sky-600 text-white',
};

export function ListaDeEspera({
    espera,
    paraAsignar,
}: {
    espera: EnEspera[];
    paraAsignar: ParaAsignar[];
}) {
    const [abierto, setAbierto] = useState(false);
    const [pedido, setPedido] = useState<DetalleDePedido | null>(null);
    const [error, setError] = useState<string | null>(null);

    /*
    | Igual que el detalle de un cadete: el pedido se pide cuando alguien abre el modal, y
    | qué modal está abierto es estado de esta pantalla, no de la URL. Ver el comentario de
    | `index.tsx`.
    */
    const abrir = async (id: number) => {
        setAbierto(true);
        setPedido(null);
        setError(null);

        try {
            const respuesta = await fetch(`/panel/pedidos/${id}/detalle`, {
                headers: { Accept: 'application/json' },
            });

            if (!respuesta.ok) {
                throw new Error(String(respuesta.status));
            }

            setPedido((await respuesta.json()) as DetalleDePedido);
        } catch {
            setError('No se pudo cargar el pedido. Probá de nuevo.');
        }
    };

    return (
        <>
            <Card className="gap-0 overflow-hidden p-0">
                <div className="bg-muted/50 flex items-center justify-between gap-2 border-b px-3 py-2">
                    <span className="text-sm font-medium">
                        Lista de espera{' '}
                        <span className="text-muted-foreground tabular-nums">
                            ({espera.length})
                        </span>
                    </span>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left">
                            <tr>
                                <th className="px-3 py-1.5 font-medium">#</th>
                                <th className="px-3 py-1.5 font-medium">Cliente</th>
                                <th className="px-3 py-1.5 font-medium">Dirección</th>
                                <th className="px-3 py-1.5 font-medium">Destino</th>
                                <th className="px-3 py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {espera.map((fila) => (
                                <FilaDeEspera
                                    key={fila.id}
                                    fila={fila}
                                    abrir={abrir}
                                />
                            ))}

                            {espera.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="text-muted-foreground px-3 py-6 text-center"
                                    >
                                        No hay pedidos esperando cadete.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            <ElModalDelPedido
                abierto={abierto}
                cerrar={() => setAbierto(false)}
                pedido={pedido}
                error={error}
                paraAsignar={paraAsignar}
            />
        </>
    );
}

function FilaDeEspera({
    fila,
    abrir,
}: {
    fila: EnEspera;
    abrir: (id: number) => void;
}) {
    return (
        <tr
            className={`hover:bg-muted/60 cursor-pointer border-t ${
                COLOR_DE_ESTADO[fila.estado] ?? ''
            }`}
            onDoubleClick={() => abrir(fila.id)}
            title={`${fila.estado}${fila.numero === null ? '' : ` · pedido ${fila.numero}`}`}
        >
            <td className="px-3 py-1.5 font-medium tabular-nums">
                {fila.numero_corto ?? '—'}
            </td>
            <td className="max-w-40 truncate px-3 py-1.5" title={fila.cliente ?? ''}>
                {fila.cliente ?? '—'}
            </td>
            <td className="max-w-48 truncate px-3 py-1.5" title={fila.direccion ?? ''}>
                {fila.direccion ?? '—'}
            </td>
            <td className="max-w-48 truncate px-3 py-1.5" title={fila.destino ?? ''}>
                {fila.destino ?? '—'}
            </td>
            <td className="px-3 py-1.5">
                <div className="flex items-center justify-end gap-1 whitespace-nowrap">
                    <ElDistintivo distintivo={fila.distintivo} />

                    <span
                        className={`rounded px-1 py-0 text-[10px] uppercase ${
                            COLOR_DE_PLATAFORMA[fila.plataforma] ??
                            'bg-foreground text-background'
                        }`}
                    >
                        {fila.plataforma}
                    </span>

                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-5"
                        title="Abrir el pedido"
                        onClick={() => abrir(fila.id)}
                    >
                        <Pencil className="size-3" />
                    </Button>
                </div>
            </td>
        </tr>
    );
}

/**
 * El modal del pedido: lo que se puede editar, y a quién dárselo.
 *
 * Es el `modalAsignarCadete` del panel viejo. Los campos de un pedido de la app van de sólo
 * lectura, como allá: su precio lo puso el cliente y cambiarlo es cotizar, que es otra cosa.
 */
function ElModalDelPedido({
    abierto,
    cerrar,
    pedido,
    error,
    paraAsignar,
}: {
    abierto: boolean;
    cerrar: () => void;
    pedido: DetalleDePedido | null;
    error: string | null;
    paraAsignar: ParaAsignar[];
}) {
    return (
        <Dialog open={abierto} onOpenChange={(abre) => !abre && cerrar()}>
            <DialogContent className="sm:max-w-[57.6rem]">
                <DialogHeader>
                    <DialogTitle>
                        {pedido === null
                            ? 'Pedido'
                            : `Pedido ${pedido.numero ?? ''} · ${pedido.cliente ?? 'sin cliente'}`}
                    </DialogTitle>
                    {/* El distintivo primero y el nombre al lado: el mismo que la fila de
                        la lista, porque es desde ahí que se abrió esto. Lo que ya estaba
                        —estado y plataforma— sigue en la misma línea. */}
                    <DialogDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        {pedido === null ? (
                            'Buscando el pedido…'
                        ) : (
                            <>
                                <ElDistintivo
                                    distintivo={pedido.distintivo}
                                    grande
                                />
                                {pedido.cargado_por !== null && (
                                    <span className="text-foreground font-medium">
                                        {pedido.cargado_por}
                                    </span>
                                )}
                                <span>
                                    {pedido.cargado_por === null ? '' : '· '}
                                    {pedido.estado} · desde {pedido.plataforma}
                                </span>
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>

                {error !== null && <p className="text-destructive text-sm">{error}</p>}

                {pedido !== null && (
                    <Form
                        action={`/panel/pedidos/${pedido.id}/asignacion`}
                        method="patch"
                        options={{ preserveScroll: true }}
                        onSuccess={cerrar}
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                {!pedido.editable && (
                                    <p className="text-muted-foreground text-xs">
                                        Es un pedido de la app: sus campos los puso el
                                        cliente y no se editan desde acá. Cambiar el
                                        precio lo deja cotizado, esperando que lo acepte.
                                    </p>
                                )}

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Campo
                                        nombre="direccion"
                                        titulo="Dirección"
                                        valor={pedido.direccion}
                                        error={errors.direccion}
                                        soloLectura={!pedido.editable}
                                    />
                                    <Campo
                                        nombre="destino"
                                        titulo="Destino"
                                        valor={pedido.destino}
                                        error={errors.destino}
                                        soloLectura={!pedido.editable}
                                    />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Campo
                                        nombre="responsable"
                                        titulo="Responsable"
                                        valor={pedido.responsable}
                                        error={errors.responsable}
                                        soloLectura={!pedido.editable}
                                    />
                                    <Campo
                                        nombre="telefono"
                                        titulo="Teléfono"
                                        valor={pedido.telefono}
                                        error={errors.telefono}
                                        soloLectura={!pedido.editable}
                                    />
                                    <label className="grid gap-1 text-sm">
                                        <span className="text-muted-foreground">
                                            Volver al origen
                                        </span>
                                        <select
                                            name="retorno_origen"
                                            defaultValue={pedido.retorno_origen ? '1' : '0'}
                                            disabled={!pedido.editable}
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                        >
                                            <option value="0">No</option>
                                            <option value="1">Sí</option>
                                        </select>
                                    </label>
                                </div>

                                <label className="grid gap-1 text-sm">
                                    <span className="text-muted-foreground">Detalle</span>
                                    <textarea
                                        name="detalle"
                                        rows={2}
                                        defaultValue={pedido.detalle ?? ''}
                                        readOnly={!pedido.editable}
                                        maxLength={1000}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm read-only:opacity-60"
                                    />
                                    <InputError message={errors.detalle} />
                                </label>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Campo
                                        nombre="valor"
                                        titulo="Valor"
                                        valor={pedido.valor}
                                        error={errors.valor}
                                        tipo="number"
                                    />
                                    <Campo
                                        nombre="garantia"
                                        titulo="Garantía"
                                        valor={pedido.garantia}
                                        error={errors.garantia}
                                        tipo="number"
                                        soloLectura={!pedido.editable}
                                    />

                                    <label className="grid gap-1 text-sm">
                                        <span className="text-muted-foreground">
                                            Asignar cadete
                                        </span>
                                        <select
                                            name="cadete_id"
                                            defaultValue=""
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        >
                                            <option value="">Ninguno</option>
                                            {paraAsignar.map((cadete) => (
                                                <option
                                                    key={cadete.id}
                                                    value={cadete.id}
                                                >
                                                    {cadete.movil} — {cadete.nombre}{' '}
                                                    {cadete.en_la_app ? '(APP)' : '(WEB)'}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.cadete_id} />
                                        {paraAsignar.length === 0 && (
                                            <span className="text-muted-foreground text-xs">
                                                No hay nadie en la cola.
                                            </span>
                                        )}
                                    </label>
                                </div>

                                {(pedido.tipo_paquete !== null ||
                                    pedido.peso_paquete !== null ||
                                    pedido.valor_declarado !== null) && (
                                    <p className="text-muted-foreground text-xs">
                                        Paquete: {pedido.tipo_paquete ?? '—'} ·{' '}
                                        {pedido.peso_paquete ?? '—'} · declarado $
                                        {pedido.valor_declarado ?? '—'}
                                    </p>
                                )}

                                <div className="flex items-center justify-between gap-2 pt-1">
                                    <Borrar pedido={pedido} alTerminar={cerrar} />

                                    <Button type="submit" disabled={processing}>
                                        Guardar y asignar
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Borrar({
    pedido,
    alTerminar,
}: {
    pedido: DetalleDePedido;
    alTerminar: () => void;
}) {
    // Aparte del formulario de arriba: un formulario dentro de otro no es HTML válido, y el
    // navegador lo desarma de maneras que no se ven hasta que algo no se manda.
    return (
        <Button
            type="button"
            variant="destructive"
            onClick={() => {
                if (
                    !confirm(
                        `Se elimina el pedido ${pedido.numero ?? ''} de ${pedido.cliente ?? 'sin cliente'}.\n\n¿Seguir?`,
                    )
                ) {
                    return;
                }

                router.delete(`/panel/pedidos/${pedido.id}`, {
                    preserveScroll: true,
                    onSuccess: alTerminar,
                });
            }}
        >
            Eliminar
        </Button>
    );
}

/**
 * Las iniciales de quien cargó el pedido, sobre su color.
 *
 * El mismo dibujo en la fila y en la cabecera del modal: si fueran dos, la primera vez que
 * alguien cambie un color quedarían distintos y el distintivo dejaría de servir para lo
 * único que sirve, que es reconocer los propios de un vistazo.
 *
 * El color de texto lo decide el servidor —`User::colorContraste`— porque depende de la
 * luminancia del fondo, y hay colores claros como el #f3ff47 de una de las cuentas sobre los
 * que el blanco no se lee.
 */
function ElDistintivo({
    distintivo,
    grande = false,
}: {
    distintivo: Distintivo | null;
    grande?: boolean;
}) {
    if (distintivo === null) {
        return null;
    }

    return (
        <span
            className={`rounded uppercase ${
                grande ? 'px-1.5 py-0.5 text-xs' : 'px-1 py-0 text-[10px]'
            }`}
            style={{
                backgroundColor: distintivo.color,
                color: distintivo.contraste ?? undefined,
            }}
            title={`Lo cargó ${distintivo.nombre}`}
        >
            {distintivo.iniciales}
        </span>
    );
}

function Campo({
    nombre,
    titulo,
    valor,
    error,
    tipo = 'text',
    soloLectura = false,
}: {
    nombre: string;
    titulo: string;
    valor: string | null;
    error?: string;
    tipo?: string;
    soloLectura?: boolean;
}) {
    return (
        <label className="grid gap-1 text-sm">
            <span className="text-muted-foreground">{titulo}</span>
            <Input
                name={nombre}
                type={tipo}
                step={tipo === 'number' ? '0.01' : undefined}
                defaultValue={valor ?? ''}
                readOnly={soloLectura}
                className="read-only:opacity-60"
            />
            <InputError message={error} />
        </label>
    );
}
