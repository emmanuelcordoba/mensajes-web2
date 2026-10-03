import { Form, Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| Los clientes.
|
| Es la pantalla del menú lateral viejo, con sus mismos filtros: origen y una búsqueda
| sobre un campo elegido. El campo se elige a propósito en lugar de buscar en los cuatro
| a la vez: son 11.057 clientes, y buscar por un campo usa su índice mientras que un OR
| entre cuatro columnas no usa ninguno.
|
| ⚠️ El botón de baja se dibuja según lo que dice el servidor, y cuando no se puede, el
| motivo se muestra en lugar del botón. Dar de baja un cliente se lleva sus pedidos y su
| cuenta de la app, y hay un caso en que se niega: ver BajaDeCliente.
*/

type ClienteEnLista = {
    id: number;
    numero: number;
    nombre: string;
    direccion: string;
    telefono: string;
    de_la_app: boolean;
    pedidos: number;
    puede_darse_de_baja: boolean;
    por_que_no: string | null;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

type Filtros = { origen: string; campo: string; buscar: string };

const ETIQUETAS: Record<string, string> = {
    nombre: 'Nombre',
    numero: 'N°',
    direccion: 'Dirección',
    telefono: 'Teléfono',
};

export default function ClientesIndex({
    clientes,
    filtros,
    campos,
}: {
    clientes: Paginado<ClienteEnLista>;
    filtros: Filtros;
    campos: string[];
}) {
    return (
        <>
            <Head title="Clientes" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Clientes
                    </h1>

                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm">
                            {clientes.from ?? 0}–{clientes.to ?? 0} de{' '}
                            {clientes.total}
                        </span>
                        <Button asChild size="sm">
                            <Link href="/panel/clientes/create">
                                Nuevo cliente
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card className="p-4">
                    <form
                        method="get"
                        action="/panel/clientes"
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const datos = new FormData(e.currentTarget);
                            router.get('/panel/clientes', {
                                origen: String(datos.get('origen') ?? ''),
                                campo: String(datos.get('campo') ?? ''),
                                buscar: String(datos.get('buscar') ?? ''),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">Origen</span>
                            <select
                                name="origen"
                                defaultValue={filtros.origen}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Todos</option>
                                <option value="app">App</option>
                                <option value="panel">Panel</option>
                            </select>
                        </label>

                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">Buscar por</span>
                            <select
                                name="campo"
                                defaultValue={filtros.campo}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                {campos.map((campo) => (
                                    <option key={campo} value={campo}>
                                        {ETIQUETAS[campo] ?? campo}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid flex-1 gap-1 text-sm">
                            <span className="text-muted-foreground">Texto</span>
                            <Input
                                name="buscar"
                                defaultValue={filtros.buscar}
                                placeholder="Qué buscás"
                            />
                        </label>

                        <Button type="submit" variant="secondary">
                            Buscar
                        </Button>

                        {(filtros.buscar !== '' || filtros.origen !== '') && (
                            <Button asChild variant="ghost">
                                <Link href="/panel/clientes">Limpiar</Link>
                            </Button>
                        )}
                    </form>
                </Card>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">N°</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Dirección</th>
                                    <th className="px-4 py-3 font-medium">Teléfono</th>
                                    <th className="px-4 py-3 font-medium">Origen</th>
                                    <th className="px-4 py-3 font-medium">Pedidos</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {clientes.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Ningún cliente con ese criterio.
                                        </td>
                                    </tr>
                                )}

                                {clientes.data.map((cliente) => (
                                    <tr
                                        key={cliente.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 tabular-nums">
                                            {cliente.numero}
                                        </td>
                                        <td className="px-4 py-3">{cliente.nombre}</td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {cliente.direccion}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cliente.telefono}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    cliente.de_la_app
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {cliente.de_la_app ? 'App' : 'Panel'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {cliente.pedidos.toLocaleString('es-AR')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={`/panel/clientes/${cliente.id}/edit`}
                                                    >
                                                        Editar
                                                    </Link>
                                                </Button>

                                                <Baja cliente={cliente} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {clientes.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {clientes.links.map((link, i) =>
                            link.url === null ? (
                                <span
                                    key={i}
                                    className="text-muted-foreground px-3 py-1.5 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <Link
                                    key={i}
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

/**
 * La baja, o el motivo por el que no se puede.
 *
 * Cuando la regla dice que no, se muestra por qué en lugar de un botón que iba a fallar.
 * Es lo que el sistema viejo contestaba recién después de apretarlo.
 */
function Baja({ cliente }: { cliente: ClienteEnLista }) {
    if (!cliente.puede_darse_de_baja) {
        return cliente.por_que_no === null ? null : (
            <span className="text-muted-foreground max-w-56 text-right text-xs">
                {cliente.por_que_no}
            </span>
        );
    }

    return (
        <Form
            action={`/panel/clientes/${cliente.id}`}
            method="delete"
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(
                    `Se dan de baja también sus ${cliente.pedidos} pedidos y su cuenta de la app, si tiene. ¿Seguir?`,
                )
            }
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    size="sm"
                    variant="destructive"
                    disabled={processing}
                >
                    Dar de baja
                </Button>
            )}
        </Form>
    );
}

ClientesIndex.layout = {
    breadcrumbs: [{ title: 'Clientes', href: '/panel/clientes' }],
};
