import { Form, Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

/*
| El carrusel de publicidades de la app de clientes.
|
| ⚠️ Las imágenes se piden con una URL firmada que dura media hora: el disco donde viven
| es privado, y en el mismo disco están los documentos de identidad de las postulaciones.
| Ver ImagenesDeLaApp.
|
| Cuando una imagen no está subida todavía, el servidor manda null y se muestra el hueco
| en lugar de una imagen rota, que no diría nada.
*/

type Publicidad = {
    id: number;
    url: string;
    visible: boolean;
    imagen: string | null;
};

export default function PublicidadIndex({
    publicidades,
    puede_borrar,
}: {
    publicidades: Publicidad[];
    puede_borrar: boolean;
}) {
    const visibles = publicidades.filter((p) => p.visible).length;

    return (
        <>
            <Head title="Publicidad APP" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Publicidad APP
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {visibles} de {publicidades.length} se muestran en la app.
                        </p>
                    </div>

                    <Button asChild size="sm">
                        <Link href="/panel/publicidad-app/create">
                            Nueva publicidad
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {publicidades.map((publicidad) => (
                        <Card key={publicidad.id} className="overflow-hidden p-0">
                            <div className="bg-muted flex aspect-video items-center justify-center overflow-hidden">
                                {publicidad.imagen === null ? (
                                    <span className="text-muted-foreground text-xs">
                                        La imagen no está subida
                                    </span>
                                ) : (
                                    <img
                                        src={publicidad.imagen}
                                        alt=""
                                        className="h-full w-full object-cover"
                                    />
                                )}
                            </div>

                            <div className="space-y-3 p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <a
                                        href={publicidad.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-muted-foreground truncate text-xs underline underline-offset-2"
                                        title={publicidad.url}
                                    >
                                        {publicidad.url}
                                    </a>
                                    <Badge
                                        variant={
                                            publicidad.visible
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {publicidad.visible ? 'Se muestra' : 'Oculta'}
                                    </Badge>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={`/panel/publicidad-app/${publicidad.id}/edit`}
                                        >
                                            Editar
                                        </Link>
                                    </Button>

                                    <Form
                                        action={`/panel/publicidad-app/${publicidad.id}/visibilidad`}
                                        method="patch"
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                {publicidad.visible
                                                    ? 'Ocultar'
                                                    : 'Mostrar'}
                                            </Button>
                                        )}
                                    </Form>

                                    {puede_borrar && (
                                        <Form
                                            action={`/panel/publicidad-app/${publicidad.id}`}
                                            method="delete"
                                            options={{ preserveScroll: true }}
                                            onBefore={() =>
                                                confirm(
                                                    'Se da de baja la publicidad. ¿Seguir?',
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
                            </div>
                        </Card>
                    ))}
                </div>
            </div>
        </>
    );
}

PublicidadIndex.layout = {
    breadcrumbs: [
        { title: 'Publicidad APP', href: '/panel/publicidad-app' },
    ],
};
