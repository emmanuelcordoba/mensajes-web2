import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';

/*
 * La cola de trabajo del panel: los pedidos que esperan un cadete.
 *
 * El orden lo decide el servidor y acá no se re-ordena: los resueltos arriba y
 * después el que espera hace más tiempo, que es el que hay que atender. Ver
 * Pedido::scopeEsperandoCadete().
 */

type PedidoEnLista = {
    id: number;
    numero: number | null;
    estado: string;
    cliente: string | null;
    direccion: string | null;
    destino: string | null;
    valor: string | null;
    actualizado: string | null;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

/** Los que ya no esperan a nadie se ven distinto de los que sí. */
const RESUELTOS = ['Cancelado', 'Rechazado'];

function EstadoBadge({ estado }: { estado: string }) {
    return (
        <Badge variant={RESUELTOS.includes(estado) ? 'secondary' : 'default'}>
            {estado}
        </Badge>
    );
}

/** Hace cuánto, en palabras. Sin librería: es una sola cuenta. */
function haceCuanto(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    const minutos = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutos < 1) {
        return 'ahora';
    }
    if (minutos < 60) {
        return `hace ${minutos} min`;
    }

    const horas = Math.floor(minutos / 60);

    if (horas < 24) {
        return `hace ${horas} h`;
    }

    return `hace ${Math.floor(horas / 24)} d`;
}

function Monto({ valor }: { valor: string | null }) {
    if (valor === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    // El valor llega como cadena a propósito, para no perder centavos: se formatea,
    // no se convierte a número.
    const [entera, decimal = '00'] = valor.split('.');
    const conMiles = entera.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return <span className="tabular-nums">${conMiles},{decimal}</span>;
}

export default function PedidosIndex({ pedidos }: { pedidos: Paginado<PedidoEnLista> }) {
    return (
        <>
            <Head title="Pedidos" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between">
                    <h1 className="text-xl font-semibold">Esperando cadete</h1>
                    <p className="text-muted-foreground text-sm">
                        {pedidos.total === 0
                            ? 'Ninguno'
                            : `${pedidos.from}–${pedidos.to} de ${pedidos.total}`}
                    </p>
                </div>

                {pedidos.data.length === 0 ? (
                    <Card className="text-muted-foreground p-8 text-center text-sm">
                        No hay pedidos esperando un cadete.
                    </Card>
                ) : (
                    <Card className="overflow-x-auto p-0">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">N°</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                    <th className="px-4 py-3 font-medium">Cliente</th>
                                    <th className="px-4 py-3 font-medium">Origen</th>
                                    <th className="px-4 py-3 font-medium">Destino</th>
                                    <th className="px-4 py-3 text-right font-medium">Valor</th>
                                    <th className="px-4 py-3 font-medium">Actualizado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pedidos.data.map((pedido) => (
                                    <tr key={pedido.id} className="border-b last:border-0">
                                        <td className="px-4 py-3 tabular-nums">
                                            {pedido.numero ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <EstadoBadge estado={pedido.estado} />
                                        </td>
                                        <td className="px-4 py-3">{pedido.cliente ?? '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {pedido.direccion ?? '—'}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {pedido.destino ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Monto valor={pedido.valor} />
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                                            {haceCuanto(pedido.actualizado)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                )}

                {pedidos.links.length > 3 && (
                    <nav className="flex flex-wrap gap-1">
                        {pedidos.links.map((link) =>
                            link.url === null ? (
                                <span
                                    key={link.label}
                                    className="text-muted-foreground px-3 py-1.5 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <Link
                                    key={link.label}
                                    href={link.url}
                                    className={`rounded-md px-3 py-1.5 text-sm ${
                                        link.active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-muted'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ),
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

PedidosIndex.layout = {
    breadcrumbs: [{ title: 'Pedidos', href: '/panel/pedidos' }],
};
