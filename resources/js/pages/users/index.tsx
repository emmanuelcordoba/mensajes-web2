import { Form, Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

/*
| Las cuentas del panel.
|
| Es la pantalla de Usuarios del menú lateral viejo, con sus mismas columnas. Muestra
| sólo los cuatro roles que usan el panel o la API: las cuentas de cadete y las de la app
| no se administran desde acá.
|
| ⚠️ Los botones de editar y borrar se dibujan según lo que dice el servidor en
| `puede_editarse` y `puede_borrarse`, que es la misma política que aplica el controlador.
| En el sistema viejo el de borrar se dibujaba para todos y un empleado recibía 403 al
| apretarlo.
*/

type CuentaEnLista = {
    id: number;
    name: string;
    email: string;
    iniciales: string | null;
    color: string | null;
    rol: string | null;
    comercio: string | null;
    puede_editarse: boolean;
    puede_borrarse: boolean;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export default function UsuariosIndex({
    usuarios,
}: {
    usuarios: Paginado<CuentaEnLista>;
}) {
    return (
        <>
            <Head title="Usuarios" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Usuarios
                    </h1>

                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm">
                            {usuarios.from ?? 0}–{usuarios.to ?? 0} de{' '}
                            {usuarios.total}
                        </span>
                        <Button asChild size="sm">
                            <Link href="/panel/users/create">Nuevo usuario</Link>
                        </Button>
                    </div>
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Email</th>
                                    <th className="px-4 py-3 font-medium">Rol</th>
                                    <th className="px-4 py-3 font-medium">Comercio</th>
                                    <th className="px-4 py-3 font-medium">Iniciales</th>
                                    <th className="px-4 py-3 font-medium text-right">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {usuarios.data.map((cuenta) => (
                                    <tr
                                        key={cuenta.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3">{cuenta.name}</td>
                                        <td className="px-4 py-3">{cuenta.email}</td>
                                        <td className="px-4 py-3">
                                            {cuenta.rol ? (
                                                <Badge variant="secondary">
                                                    {cuenta.rol}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Sin rol
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {cuenta.comercio ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Iniciales cuenta={cuenta} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                {cuenta.puede_editarse && (
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={`/panel/users/${cuenta.id}/edit`}
                                                        >
                                                            Editar
                                                        </Link>
                                                    </Button>
                                                )}

                                                {cuenta.puede_borrarse && (
                                                    <Form
                                                        action={`/panel/users/${cuenta.id}`}
                                                        method="delete"
                                                        options={{ preserveScroll: true }}
                                                        onBefore={() =>
                                                            confirm(
                                                                `Se da de baja la cuenta de ${cuenta.name}. Deja de poder entrar. ¿Seguir?`,
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
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {usuarios.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {usuarios.links.map((link, i) =>
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

/** Las dos letras del avatar, con su color si lo tiene. */
function Iniciales({ cuenta }: { cuenta: CuentaEnLista }) {
    if (!cuenta.iniciales) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            className="inline-flex size-7 items-center justify-center rounded-full text-xs font-medium"
            style={
                cuenta.color
                    ? { backgroundColor: cuenta.color, color: '#fff' }
                    : undefined
            }
        >
            {cuenta.iniciales}
        </span>
    );
}

UsuariosIndex.layout = {
    breadcrumbs: [{ title: 'Usuarios', href: '/panel/users' }],
};
