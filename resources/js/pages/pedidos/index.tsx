import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import {
    Paginacion,
    TablaDePedidos,
    type Paginado,
    type PedidoEnLista,
} from '@/components/tabla-de-pedidos';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
 * El listado de pedidos: el `/pedidos` del sistema viejo, con su mismo criterio.
 *
 * ⚠️ NO ES LA LISTA DE ESPERA. Hasta el 2026-11-03 esta pantalla mostraba la cola de
 * Inicio, así que la cola estaba dos veces y el listado general no estaba en ninguna
 * parte. Quien busca un pedido de la semana pasada viene acá; quien atiende el que
 * está esperando, a Inicio.
 *
 * ⚠️ Un comercio no ve el formulario de búsqueda ni el botón de los borrados, igual
 * que en el panel viejo. Las dos cosas las decide el servidor —`puede_buscar` y
 * `puede_ver_eliminados`—, no un `if` de rol acá.
 */

type Filtros = {
    buscar: string;
    campo: string;
    estado: string;
    desde: string;
    hasta: string;
};

/*
 * ⚠️ «Cancelado» se muestra como automático porque lo es: el único lugar del sistema que
 * pone un pedido en ese estado es el reloj de los cinco minutos
 * (`CancelarPedidoSinConfirmar`), así que no hay cancelación a mano con la que confundirlo
 * y no hace falta un filtro aparte. El valor que viaja es el estado tal cual; lo que cambia
 * es lo que se lee.
 */
const COMO_SE_LEE: Record<string, string> = {
    Cancelado: 'Cancelado (automático)',
};

export default function PedidosIndex({
    pedidos,
    filtros,
    estados,
    puede_buscar,
    puede_ver_eliminados,
}: {
    pedidos: Paginado<PedidoEnLista>;
    filtros: Filtros;
    /** Los que el listado muestra, dichos por el servidor: son su recorte, no el de acá. */
    estados: string[];
    puede_buscar: boolean;
    puede_ver_eliminados: boolean;
}) {
    const [elegidos, setElegidos] = useState<number[]>([]);

    const borrables = pedidos.data.filter((p) => p.se_puede_borrar);

    const alternar = (id: number) =>
        setElegidos((antes) =>
            antes.includes(id) ? antes.filter((otro) => otro !== id) : [...antes, id],
        );

    const alternarTodos = () =>
        setElegidos((antes) =>
            antes.length === borrables.length ? [] : borrables.map((p) => p.id),
        );

    const eliminarSeleccionados = () => {
        if (elegidos.length === 0) {
            return;
        }

        const cuantos =
            elegidos.length === 1
                ? 'el pedido seleccionado'
                : `los ${elegidos.length} pedidos seleccionados`;

        if (!confirm(`Se eliminan ${cuantos}. ¿Seguir?`)) {
            return;
        }

        router.delete('/panel/pedidos', {
            data: { pedidos: elegidos },
            preserveScroll: true,
            onSuccess: () => setElegidos([]),
        });
    };

    return (
        <>
            <Head title="Pedidos" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {puede_buscar && (
                    <Card className="p-4">
                        <Form
                            method="get"
                            action="/panel/pedidos"
                            className="flex flex-wrap items-end gap-2"
                        >
                            <div className="grid gap-1">
                                <label htmlFor="buscar" className="text-sm font-medium">
                                    Buscar
                                </label>
                                <Input
                                    id="buscar"
                                    name="buscar"
                                    defaultValue={filtros.buscar}
                                    maxLength={255}
                                    className="w-56"
                                />
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="campo" className="text-sm font-medium">
                                    En
                                </label>
                                <select
                                    id="campo"
                                    name="campo"
                                    defaultValue={filtros.campo || 'cliente'}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="cliente">Cliente</option>
                                    <option value="direccion">Direcciones</option>
                                    <option value="cadete">Cadete</option>
                                </select>
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="estado" className="text-sm font-medium">
                                    Estado
                                </label>
                                <select
                                    id="estado"
                                    name="estado"
                                    defaultValue={filtros.estado}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {/* En blanco son los siete, que es como entra la
                                        pantalla. El servidor ignora un estado que no
                                        reconoce, así que esto no puede vaciar el listado. */}
                                    <option value="">Todos</option>
                                    {estados.map((estado) => (
                                        <option key={estado} value={estado}>
                                            {COMO_SE_LEE[estado] ?? estado}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="desde" className="text-sm font-medium">
                                    Desde
                                </label>
                                <Input
                                    id="desde"
                                    name="desde"
                                    type="date"
                                    defaultValue={filtros.desde}
                                    className="w-40"
                                />
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="hasta" className="text-sm font-medium">
                                    Hasta
                                </label>
                                <Input
                                    id="hasta"
                                    name="hasta"
                                    type="date"
                                    defaultValue={filtros.hasta}
                                    className="w-40"
                                />
                            </div>

                            <Button type="submit">Buscar</Button>

                            {(filtros.buscar !== '' || filtros.estado !== '') && (
                                <Button asChild variant="ghost">
                                    <Link href="/panel/pedidos">Limpiar</Link>
                                </Button>
                            )}
                        </Form>
                    </Card>
                )}

                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="text-xl font-semibold">Pedidos</h1>

                    <div className="flex items-center gap-2">
                        <p className="text-muted-foreground text-sm">
                            {pedidos.total === 0
                                ? 'Ninguno'
                                : `${pedidos.from}–${pedidos.to} de ${pedidos.total}`}
                        </p>

                        {puede_ver_eliminados && (
                            <Button asChild variant="secondary" size="sm">
                                <Link href="/panel/pedidos/eliminados">
                                    Pedidos eliminados
                                </Link>
                            </Button>
                        )}

                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={eliminarSeleccionados}
                            disabled={elegidos.length === 0}
                        >
                            Eliminar seleccionados
                            {elegidos.length > 0 && ` (${elegidos.length})`}
                        </Button>
                    </div>
                </div>

                <TablaDePedidos
                    pedidos={pedidos.data}
                    vacia="Ningún pedido con ese criterio."
                    seleccion={{
                        elegidos,
                        alternar,
                        alternarTodos,
                        borrables: borrables.length,
                    }}
                    acciones={(pedido) => (
                        <>
                            {/* El cupón es el papel que se le da al cadete: sin cadete
                                asignado no hay nada que imprimir, como en el listado
                                viejo. Y en otra pestaña, porque la página se imprime
                                sola al cargar y se cierra. */}
                            {pedido.se_puede_imprimir && (
                                <Button asChild size="sm" variant="secondary">
                                    <a
                                        href={`/panel/pedidos/${pedido.id}/cupon`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Imprimir
                                    </a>
                                </Button>
                            )}

                            <Button asChild size="sm" variant="secondary">
                                <Link href={`/panel/pedidos/${pedido.id}`}>Ver</Link>
                            </Button>

                            {pedido.se_puede_borrar && (
                                <Form
                                    method="delete"
                                    action={`/panel/pedidos/${pedido.id}`}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        confirm('Se elimina el pedido. ¿Seguir?')
                                    }
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Eliminar
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    )}
                />

                <Paginacion links={pedidos.links} />
            </div>
        </>
    );
}
