import { Form, Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| Los parámetros que la oficina edita: costos, retorno, garantía.
|
| ⚠️ Se edita en la misma fila, sin modal. El panel viejo abría uno por configuración, que
| es una pantalla más para ver un campo y guardar; acá cada fila es su propio formulario y
| se ve el valor actual al lado del que se escribe.
|
| Lo que se cambia acá se lee al cotizar un pedido y al cobrarle al cadete, así que el
| servidor valida exactamente lo que la base acepta: un número sin signo, con punto
| decimal si hace falta. Ver GuardarConfiguracionRequest.
*/

type ConfiguracionEditable = {
    id: number;
    titulo: string;
    descripcion: string | null;
    valor: string | null;
    es_numerica: boolean;
};

export default function ConfiguracionesIndex({
    configuraciones,
}: {
    configuraciones: ConfiguracionEditable[];
}) {
    return (
        <>
            <Head title="Configuraciones" />

            <div className="max-w-4xl p-4">
                <Heading
                    title="Configuraciones"
                    description="Lo que se cobra. Se lee al cotizar un pedido."
                />

                <div className="space-y-3">
                    {configuraciones.map((configuracion) => (
                        <Fila key={configuracion.id} configuracion={configuracion} />
                    ))}
                </div>
            </div>
        </>
    );
}

function Fila({ configuracion }: { configuracion: ConfiguracionEditable }) {
    return (
        <Card className="p-4">
            <Form
                action={`/panel/configuraciones/${configuracion.id}`}
                method="patch"
                options={{ preserveScroll: true }}
                className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
            >
                {({ processing, errors, recentlySuccessful }) => (
                    <>
                        <div className="grid gap-2">
                            <div>
                                <p className="font-medium">{configuracion.titulo}</p>
                                {configuracion.descripcion && (
                                    <p className="text-muted-foreground text-sm">
                                        {configuracion.descripcion}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Input
                                    name="valor"
                                    defaultValue={configuracion.valor ?? ''}
                                    inputMode={
                                        configuracion.es_numerica
                                            ? 'decimal'
                                            : 'text'
                                    }
                                    className="max-w-40"
                                    aria-label={configuracion.titulo}
                                />
                                {/* La descripción se edita también, como en el modal
                                    viejo: es el texto que explica el parámetro. */}
                                <input
                                    type="hidden"
                                    name="descripcion"
                                    value={configuracion.descripcion ?? ''}
                                />
                            </div>

                            <InputError message={errors.valor} />
                        </div>

                        <div className="flex items-center gap-3">
                            {recentlySuccessful && (
                                <span className="text-muted-foreground text-sm">
                                    Guardado
                                </span>
                            )}
                            <Button type="submit" disabled={processing} size="sm">
                                Guardar
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Card>
    );
}

ConfiguracionesIndex.layout = {
    breadcrumbs: [
        { title: 'Configuraciones', href: '/panel/configuraciones' },
    ],
};
