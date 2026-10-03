import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| Las postulaciones para cadete.
|
| Es la pantalla del menú lateral viejo. Lo que se abre por omisión son las pendientes, que
| es lo que esta pantalla sirve para mirar; el filtro existe porque el legajo de alguien ya
| contratado no tenía forma de abrirse —el listado viejo no lo mostraba nunca— y es donde
| viven sus documentos.
*/

type PostulacionEnLista = {
    id: number;
    nombre: string;
    email: string;
    telefono: string;
    direccion: string;
    vehiculo: string;
    cuando: string | null;
    /** El móvil del cadete en que se convirtió, si se contrató. */
    movil: number | null;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

const ESTADOS: Record<string, string> = {
    pendientes: 'Pendientes',
    contratadas: 'Contratadas',
    todas: 'Todas',
};

export default function PostulacionesIndex({
    postulaciones,
    filtros,
    pendientes,
}: {
    postulaciones: Paginado<PostulacionEnLista>;
    filtros: { buscar: string; estado: string };
    pendientes: number;
}) {
    return (
        <>
            <Head title="Postulaciones" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Postulaciones
                        </h1>
                        {pendientes > 0 && (
                            <Badge variant="secondary">
                                {pendientes} esperando decisión
                            </Badge>
                        )}
                    </div>

                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm">
                            {postulaciones.from ?? 0}–{postulaciones.to ?? 0} de{' '}
                            {postulaciones.total}
                        </span>
                        <Button asChild size="sm">
                            <Link href="/panel/cadetes/postulaciones/create">
                                Nueva postulación
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card className="p-4">
                    <form
                        method="get"
                        action="/panel/cadetes/postulaciones"
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const datos = new FormData(e.currentTarget);
                            router.get('/panel/cadetes/postulaciones', {
                                estado: String(datos.get('estado') ?? ''),
                                buscar: String(datos.get('buscar') ?? ''),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">Mostrar</span>
                            <select
                                name="estado"
                                defaultValue={filtros.estado}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                {Object.entries(ESTADOS).map(([valor, texto]) => (
                                    <option key={valor} value={valor}>
                                        {texto}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid flex-1 gap-1 text-sm">
                            <span className="text-muted-foreground">
                                Nombre, apellido o DNI
                            </span>
                            <Input
                                name="buscar"
                                defaultValue={filtros.buscar}
                                placeholder="Qué buscás"
                            />
                        </label>

                        <Button type="submit" variant="secondary">
                            Buscar
                        </Button>

                        {(filtros.buscar !== '' || filtros.estado !== 'pendientes') && (
                            <Button asChild variant="ghost">
                                <Link href="/panel/cadetes/postulaciones">Limpiar</Link>
                            </Button>
                        )}
                    </form>
                </Card>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Cuándo</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Email</th>
                                    <th className="px-4 py-3 font-medium">Teléfono</th>
                                    <th className="px-4 py-3 font-medium">Dirección</th>
                                    <th className="px-4 py-3 font-medium">Vehículo</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {postulaciones.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Ninguna postulación con ese criterio.
                                        </td>
                                    </tr>
                                )}

                                {postulaciones.data.map((postulacion) => (
                                    <tr
                                        key={postulacion.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                                            {postulacion.cuando}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <div className="flex items-center gap-2">
                                                {postulacion.nombre}
                                                {postulacion.movil !== null && (
                                                    <Badge variant="outline">
                                                        Móvil {postulacion.movil}
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            <span
                                                className="block max-w-44 truncate"
                                                title={postulacion.email}
                                            >
                                                {postulacion.email}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {postulacion.telefono}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            <span
                                                className="block max-w-56 truncate"
                                                title={postulacion.direccion}
                                            >
                                                {postulacion.direccion}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {postulacion.vehiculo}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <Button asChild size="sm" variant="outline">
                                                <Link
                                                    href={`/panel/cadetes/postulaciones/${postulacion.id}`}
                                                >
                                                    Ver legajo
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {postulaciones.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {postulaciones.links.map((link, i) =>
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

PostulacionesIndex.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Postulaciones', href: '/panel/cadetes/postulaciones' },
    ],
};
