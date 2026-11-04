import { Form, Head, Link } from '@inertiajs/react';

import {
    Paginacion,
    TablaDePedidos,
    type Paginado,
    type PedidoEnLista,
} from '@/components/tabla-de-pedidos';
import { Button } from '@/components/ui/button';

/*
 * Los pedidos borrados, para poder recuperarlos.
 *
 * ⚠️ Importa más de lo que parece que esta pantalla exista: la oficina se deshace de
 * los pedidos que no llegan al final borrándolos, así que la mayor parte de los
 * estados intermedios está acá. Sin esto, un borrado por error no tiene vuelta desde
 * el panel.
 *
 * Es de oficina: un comercio puede borrar los suyos y no puede recuperarlos. Lo dice
 * la ruta y lo dice `PedidoPolicy::verEliminados()`.
 */

export default function PedidosEliminados({
    pedidos,
}: {
    pedidos: Paginado<PedidoEnLista>;
}) {
    return (
        <>
            <Head title="Pedidos eliminados" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="text-xl font-semibold">Pedidos eliminados</h1>

                    <div className="flex items-center gap-2">
                        <p className="text-muted-foreground text-sm">
                            {pedidos.total === 0
                                ? 'Ninguno'
                                : `${pedidos.from}–${pedidos.to} de ${pedidos.total}`}
                        </p>

                        <Button asChild variant="secondary" size="sm">
                            <Link href="/panel/pedidos">Volver a los pedidos</Link>
                        </Button>
                    </div>
                </div>

                <TablaDePedidos
                    pedidos={pedidos.data}
                    vacia="No hay pedidos eliminados."
                    acciones={(pedido) => (
                        <Form
                            method="patch"
                            action={`/panel/pedidos/${pedido.id}/restaurar`}
                            options={{ preserveScroll: true }}
                            onBefore={() =>
                                confirm('El pedido vuelve al listado. ¿Seguir?')
                            }
                        >
                            {({ processing }) => (
                                <Button type="submit" size="sm" disabled={processing}>
                                    Restaurar
                                </Button>
                            )}
                        </Form>
                    )}
                />

                <Paginacion links={pedidos.links} />
            </div>
        </>
    );
}
