import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { EstadoBadge, Monto } from '@/components/pedido';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';

/*
 * Un pedido, con su historia.
 *
 * Lo que decide a qué pedido llega quien mira es el servidor: la policy responde por
 * esta fila, y niega con 404 para no confirmarle a un comercio que el pedido existe.
 * Acá no hay ninguna comprobación de permisos, y no debería haberla.
 */

type Log = {
    id: number;
    estado: string;
    mensaje: string | null;
    plataforma: string;
    /** Ya formateada por el servidor. */
    cuando: string | null;
};

type Pedido = {
    id: number;
    numero: number | null;
    estado: string;
    plataforma: string;
    /** Ya formateadas por el servidor, que es el que sabe la zona horaria. */
    creado: string | null;
    actualizado: string | null;

    cliente: string | null;
    cliente_numero: number | null;
    telefono: string | null;
    responsable: string | null;

    direccion: string | null;
    destino: string | null;
    detalle: string | null;
    retorno_origen: boolean;
    gastronomia: boolean;

    valor: string | null;
    garantia: string | null;
    valor_declarado: string | null;

    tipo_paquete: string | null;
    peso_paquete: string | null;

    movil: number | null;
    cargado_por: string | null;

    logs: Log[];
};

/** Un dato suelto. No se dibuja si no hay nada que mostrar. */
function Dato({
    etiqueta,
    children,
    siempre = false,
}: {
    etiqueta: string;
    children: ReactNode;
    siempre?: boolean;
}) {
    // Una fila que dice «Peso: —» ocupa lo mismo que una que dice algo, y la pantalla
    // de un pedido se mira para encontrar un dato, no para contar los que faltan. Los
    // que siempre importan —el origen, el destino— se muestran igual con un guión.
    const vacio =
        children === null || children === undefined || children === '' || children === false;

    if (vacio && !siempre) {
        return null;
    }

    return (
        <div>
            <dt className="text-muted-foreground text-xs">{etiqueta}</dt>
            <dd className="text-sm">{vacio ? <span className="text-muted-foreground">—</span> : children}</dd>
        </div>
    );
}

export default function PedidoShow({ pedido }: { pedido: Pedido }) {
    const titulo = pedido.numero === null ? 'Pedido sin número' : `Pedido N° ${pedido.numero}`;

    return (
        <>
            <Head title={titulo} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-xl font-semibold">{titulo}</h1>
                    <EstadoBadge estado={pedido.estado} />
                    <Badge variant="outline" className="uppercase">
                        {pedido.plataforma}
                    </Badge>
                    <span className="text-muted-foreground ml-auto text-sm">
                        {pedido.creado ?? '—'}
                    </span>
                </div>

                <Card className="p-4">
                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Dato etiqueta="Cliente" siempre>
                            {pedido.cliente}
                            {pedido.cliente_numero !== null && (
                                <span className="text-muted-foreground"> · N° {pedido.cliente_numero}</span>
                            )}
                        </Dato>
                        <Dato etiqueta="Teléfono">{pedido.telefono}</Dato>
                        <Dato etiqueta="Responsable">{pedido.responsable}</Dato>

                        <Dato etiqueta="Origen" siempre>
                            {pedido.direccion}
                        </Dato>
                        <Dato etiqueta="Destino" siempre>
                            {pedido.destino}
                        </Dato>
                        <Dato etiqueta="Detalle">{pedido.detalle}</Dato>

                        <Dato etiqueta="Valor" siempre>
                            <Monto valor={pedido.valor} />
                        </Dato>
                        <Dato etiqueta="Garantía">
                            {pedido.garantia === null ? null : <Monto valor={pedido.garantia} />}
                        </Dato>
                        <Dato etiqueta="Valor declarado">
                            {pedido.valor_declarado === null ? null : (
                                <Monto valor={pedido.valor_declarado} />
                            )}
                        </Dato>

                        <Dato etiqueta="Móvil">
                            {pedido.movil === null ? null : `Móvil ${pedido.movil}`}
                        </Dato>
                        <Dato etiqueta="Cargado por">{pedido.cargado_por}</Dato>
                        <Dato etiqueta="Paquete">
                            {[pedido.tipo_paquete, pedido.peso_paquete].filter(Boolean).join(' · ') || null}
                        </Dato>

                        <Dato etiqueta="Gastronomía">{pedido.gastronomia ? 'Sí' : null}</Dato>
                        <Dato etiqueta="Vuelve al origen">{pedido.retorno_origen ? 'Sí' : null}</Dato>
                    </dl>
                </Card>

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Historia</h2>

                    {pedido.logs.length === 0 ? (
                        <Card className="text-muted-foreground p-4 text-sm">
                            {/* No es raro: el registro de estados existe desde después de muchos
                                pedidos viejos, y un pedido migrado puede no tener ninguno. */}
                            No quedó registro de los cambios de estado. El estado actual es{' '}
                            <strong>{pedido.estado}</strong>, actualizado {pedido.actualizado ?? 'sin fecha'}.
                        </Card>
                    ) : (
                        <Card className="overflow-x-auto p-0">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Cuándo</th>
                                        <th className="px-4 py-2 font-medium">Estado</th>
                                        <th className="px-4 py-2 font-medium">Detalle</th>
                                        <th className="px-4 py-2 font-medium">Origen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pedido.logs.map((log) => (
                                        <tr key={log.id} className="border-b last:border-0">
                                            <td className="px-4 py-2 whitespace-nowrap tabular-nums">
                                                {log.cuando ?? '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                <EstadoBadge estado={log.estado} />
                                            </td>
                                            <td className="px-4 py-2">{log.mensaje ?? '—'}</td>
                                            <td className="text-muted-foreground px-4 py-2 uppercase">
                                                {log.plataforma}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                    )}
                </div>

                <Link href="/panel/pedidos" className="text-sm underline">
                    Volver a la cola
                </Link>
            </div>
        </>
    );
}

PedidoShow.layout = {
    breadcrumbs: [
        { title: 'Pedidos', href: '/panel/pedidos' },
        { title: 'Pedido', href: '#' },
    ],
};
