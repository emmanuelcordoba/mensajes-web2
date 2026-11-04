import { type ReactNode } from 'react';

import { EstadoBadge, Monto } from '@/components/pedido';
import { Card } from '@/components/ui/card';

/*
 * La tabla de pedidos, que es la misma en el listado y en los eliminados.
 *
 * Son las diez columnas del listado viejo, en su orden. Lo único que cambia entre las
 * dos pantallas es la columna de acciones y si hay casillas, y las dos cosas entran
 * por props: el servidor ya decidió qué se puede hacer con cada fila —`se_puede_borrar`—
 * y acá no se vuelve a preguntar cuál de los ocho estados es el final.
 */

export type PedidoEnLista = {
    id: number;
    numero: number | null;
    estado: string;
    cuando: string | null;
    cliente: string | null;
    direccion: string | null;
    destino: string | null;
    telefono: string | null;
    garantia: string | null;
    valor: string | null;
    movil: number | null;
    cadete: string | null;
    se_puede_borrar: boolean;
    se_puede_imprimir: boolean;
};

export type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export function TablaDePedidos({
    pedidos,
    vacia,
    acciones,
    seleccion,
}: {
    pedidos: PedidoEnLista[];
    vacia: string;
    acciones: (pedido: PedidoEnLista) => ReactNode;
    seleccion?: {
        elegidos: number[];
        alternar: (id: number) => void;
        alternarTodos: () => void;
        /** Cuántos de los visibles se pueden borrar, para el estado de la casilla de arriba. */
        borrables: number;
    };
}) {
    const columnas = seleccion === undefined ? 11 : 12;

    return (
        <Card className="overflow-hidden p-0">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            {seleccion !== undefined && (
                                <th className="px-3 py-3">
                                    <input
                                        type="checkbox"
                                        aria-label="Seleccionar todos"
                                        checked={
                                            seleccion.borrables > 0 &&
                                            seleccion.elegidos.length === seleccion.borrables
                                        }
                                        onChange={seleccion.alternarTodos}
                                        disabled={seleccion.borrables === 0}
                                    />
                                </th>
                            )}
                            <th className="px-4 py-3 font-medium">Fecha</th>
                            <th className="px-4 py-3 font-medium">Cliente</th>
                            <th className="px-4 py-3 font-medium">Dirección</th>
                            <th className="px-4 py-3 font-medium">Destino</th>
                            <th className="px-4 py-3 font-medium">Teléfono</th>
                            <th className="px-4 py-3 text-right font-medium">Garantía</th>
                            <th className="px-4 py-3 text-right font-medium">Valor</th>
                            <th className="px-4 py-3 font-medium">Móvil</th>
                            <th className="px-4 py-3 font-medium">Cadete</th>
                            <th className="px-4 py-3 font-medium">Estado</th>
                            <th className="px-4 py-3 font-medium">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pedidos.length === 0 && (
                            <tr>
                                <td
                                    colSpan={columnas}
                                    className="text-muted-foreground px-4 py-8 text-center"
                                >
                                    {vacia}
                                </td>
                            </tr>
                        )}

                        {pedidos.map((pedido) => (
                            <tr
                                key={pedido.id}
                                className="hover:bg-muted/40 border-b last:border-0"
                            >
                                {seleccion !== undefined && (
                                    <td className="px-3 py-3">
                                        {/* Un pedido finalizado no se borra: tampoco tiene
                                            casilla, como en el listado viejo. */}
                                        {pedido.se_puede_borrar && (
                                            <input
                                                type="checkbox"
                                                aria-label={`Seleccionar el pedido de ${pedido.cuando}`}
                                                checked={seleccion.elegidos.includes(pedido.id)}
                                                onChange={() => seleccion.alternar(pedido.id)}
                                            />
                                        )}
                                    </td>
                                )}
                                <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                                    {pedido.cuando}
                                </td>
                                <td className="px-4 py-3">{pedido.cliente}</td>
                                <td className="px-4 py-3">{pedido.direccion}</td>
                                <td className="px-4 py-3">{pedido.destino}</td>
                                <td className="px-4 py-3 whitespace-nowrap">
                                    {pedido.telefono}
                                </td>
                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                    <Monto valor={pedido.garantia} />
                                </td>
                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                    <Monto valor={pedido.valor} />
                                </td>
                                <td className="px-4 py-3">{pedido.movil}</td>
                                <td className="px-4 py-3">{pedido.cadete}</td>
                                <td className="px-4 py-3">
                                    <EstadoBadge estado={pedido.estado} />
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-1">
                                        {acciones(pedido)}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

export function Paginacion({ links }: { links: Paginado<unknown>['links'] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap gap-1" aria-label="Paginación">
            {links.map((link) =>
                link.url === null ? (
                    <span
                        key={link.label}
                        className="text-muted-foreground px-3 py-1 text-sm"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <a
                        key={link.label}
                        href={link.url}
                        className={`rounded px-3 py-1 text-sm ${
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
