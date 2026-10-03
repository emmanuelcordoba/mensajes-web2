import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El legajo de quien se postula, y la decisión.
|
| ⚠️ Acá están los documentos de identidad de una persona —DNI de frente y de dorso—, que
| no están en ningún otro respaldo (DATA-9). Llegan como URL firmadas que duran media hora,
| nunca como la ruta del archivo.
|
| ⚠️ Y los dos del DNI arrancan tapados, detrás de un botón. No es seguridad —quien abre
| esta pantalla puede verlos— sino que esta pantalla se abre en una oficina con gente
| alrededor, y el DNI de alguien no tiene por qué quedar puesto en la pantalla mientras se
| lee el resto. El panel viejo los mostraba los cuatro de entrada; también los mostraba
| rotos, porque apuntaba al disco público (PANEL-20).
*/

type Decision = { permitido: boolean; motivo: string | null };

type Postulacion = {
    id: number;
    nombre: string;
    nombres: string;
    apellidos: string;
    dni: number;
    fecha_nacimiento: string | null;
    direccion: string;
    telefono: string;
    email: string;
    vehiculo: string;
    recomendado_por: string | null;
    cuando: string | null;
    movil: number | null;
    documentos: Record<string, string | null>;
    respuestas: { pregunta: string; respuesta: string }[];
};

export default function PostulacionShow({
    postulacion,
    identidad,
    etiquetas,
    contratar,
    rechazar,
}: {
    postulacion: Postulacion;
    identidad: string[];
    etiquetas: Record<string, string>;
    contratar: Decision & { siguiente_movil: number };
    rechazar: Decision;
}) {
    return (
        <>
            <Head title={postulacion.nombre} />

            <div className="max-w-5xl space-y-6 p-4">
                <Heading
                    title={postulacion.nombre}
                    description={
                        postulacion.movil === null
                            ? `Se postuló el ${postulacion.cuando ?? 'sin fecha'}`
                            : `Contratada · móvil N° ${postulacion.movil}`
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="space-y-4 p-6">
                        <h2 className="text-sm font-medium">La persona</h2>

                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                            <Dato nombre="Nombres" valor={postulacion.nombres} />
                            <Dato nombre="Apellidos" valor={postulacion.apellidos} />
                            <Dato nombre="DNI" valor={String(postulacion.dni)} />
                            <Dato
                                nombre="Fecha de nacimiento"
                                valor={postulacion.fecha_nacimiento}
                            />
                            <Dato nombre="Teléfono" valor={postulacion.telefono} />
                            <Dato nombre="Vehículo" valor={postulacion.vehiculo} />
                            <Dato nombre="Dirección" valor={postulacion.direccion} />
                            <Dato nombre="Email" valor={postulacion.email} />
                            <Dato
                                nombre="Recomendado por"
                                valor={postulacion.recomendado_por}
                            />
                        </dl>
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-1">
                            <h2 className="text-sm font-medium">El cuestionario</h2>
                            {/* El panel viejo lo guardaba y no lo mostraba en ningún
                                lado: quien decidía contratar no veía las respuestas. */}
                            <p className="text-muted-foreground text-xs">
                                Lo que respondió al postularse.
                            </p>
                        </div>

                        <dl className="space-y-3">
                            {postulacion.respuestas.map((una) => (
                                <div
                                    key={una.pregunta}
                                    className="flex items-center justify-between gap-4 border-b pb-2 last:border-0 last:pb-0"
                                >
                                    <dt className="text-sm">{una.pregunta}</dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                una.respuesta === 'Sí'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {una.respuesta}
                                        </Badge>
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                </div>

                <Card className="space-y-4 p-6">
                    <h2 className="text-sm font-medium">Los documentos</h2>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(postulacion.documentos).map(([tipo, url]) => (
                            <Documento
                                key={tipo}
                                etiqueta={etiquetas[tipo] ?? tipo}
                                url={url}
                                tapado={identidad.includes(tipo)}
                            />
                        ))}
                    </div>
                </Card>

                <Card className="space-y-4 p-6">
                    <h2 className="text-sm font-medium">La decisión</h2>

                    {contratar.permitido ? (
                        <Form
                            action={`/panel/cadetes/postulaciones/${postulacion.id}/contratacion`}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid max-w-xs gap-2">
                                        <Label htmlFor="numero_movil">
                                            N° de móvil
                                        </Label>
                                        <Input
                                            id="numero_movil"
                                            name="numero_movil"
                                            type="number"
                                            min={1}
                                            defaultValue={contratar.siguiente_movil}
                                            required
                                        />
                                        {/* ⚠️ El número propuesto es el último más uno,
                                            y nada más que una propuesta: en la práctica
                                            se recicla el del cadete que se va, así que
                                            los números vuelven para atrás (PANEL-5). */}
                                        <p className="text-muted-foreground text-xs">
                                            Es con lo que lo va a nombrar la central.
                                        </p>
                                        <InputError message={errors.numero_movil} />
                                    </div>

                                    <p className="text-muted-foreground text-sm">
                                        Al contratar se crea el cadete y su cuenta de la
                                        app, y la contraseña se muestra{' '}
                                        <strong>una sola vez</strong>.
                                    </p>

                                    <Button type="submit" disabled={processing}>
                                        Contratar
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {contratar.motivo ?? 'No se puede contratar.'}
                        </p>
                    )}

                    <div className="border-t pt-4">
                        {rechazar.permitido ? (
                            <Form
                                action={`/panel/cadetes/postulaciones/${postulacion.id}`}
                                method="delete"
                                onBefore={() =>
                                    confirm(
                                        `Se rechaza la postulación de ${postulacion.nombre}.\n\n` +
                                            'Sale del listado, pero no se borra: sus documentos quedan guardados.\n\n¿Seguir?',
                                    )
                                }
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Rechazar
                                    </Button>
                                )}
                            </Form>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {rechazar.motivo ?? 'No se puede rechazar.'}
                            </p>
                        )}
                    </div>
                </Card>

                <Button asChild variant="ghost">
                    <Link href="/panel/cadetes/postulaciones">Volver al listado</Link>
                </Button>
            </div>
        </>
    );
}

function Dato({ nombre, valor }: { nombre: string; valor: string | null }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{nombre}</dt>
            <dd className="text-sm">
                {valor === null || valor === '' ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    valor
                )}
            </dd>
        </div>
    );
}

/**
 * Una imagen del legajo.
 *
 * `url` llega en null cuando el archivo no está: se muestra la ausencia en lugar de una
 * imagen rota, que es lo que hacía el panel viejo con las cuatro.
 */
function Documento({
    etiqueta,
    url,
    tapado,
}: {
    etiqueta: string;
    url: string | null;
    tapado: boolean;
}) {
    const [verlo, setVerlo] = useState(false);

    return (
        <div className="grid gap-2">
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-xs">{etiqueta}</span>
                {tapado && url !== null && (
                    <button
                        type="button"
                        className="text-xs underline underline-offset-2"
                        onClick={() => setVerlo((antes) => !antes)}
                    >
                        {verlo ? 'Ocultar' : 'Mostrar'}
                    </button>
                )}
            </div>

            {url === null ? (
                <div className="text-muted-foreground flex aspect-[4/3] items-center justify-center rounded-md border border-dashed text-xs">
                    No está cargado
                </div>
            ) : tapado && !verlo ? (
                <button
                    type="button"
                    onClick={() => setVerlo(true)}
                    className="text-muted-foreground hover:bg-muted flex aspect-[4/3] items-center justify-center rounded-md border text-xs"
                >
                    Documento de identidad
                </button>
            ) : (
                <a href={url} target="_blank" rel="noreferrer">
                    <img
                        src={url}
                        alt={etiqueta}
                        className="aspect-[4/3] w-full rounded-md border object-cover"
                    />
                </a>
            )}
        </div>
    );
}

PostulacionShow.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Postulaciones', href: '/panel/cadetes/postulaciones' },
    ],
};
