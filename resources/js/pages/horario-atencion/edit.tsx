import { Form, Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El horario en que la empresa atiende.
|
| ⚠️ Es UNA SOLA FILA, así que esta pantalla no tiene listado ni alta: muestra lo que hay
| y lo guarda. El panel viejo la trataba como un recurso con «nuevo horario», y usarlo dos
| veces dejaba dos filas con la aplicación leyendo la primera.
|
| ⚠️ Un horario que cruza la medianoche —de 22:00 a 02:00— es válido, y el modelo lo
| evalúa invirtiendo la comparación. Por eso la pantalla no exige que «hasta» sea mayor
| que «desde»: lo dice y sigue.
*/

type HorarioEditable = {
    desde: string;
    hasta: string;
    mensaje_horario: string;
    mensaje_confirmacion: string;
    abierto_ahora: boolean;
};

export default function HorarioEdit({
    horario,
}: {
    horario: HorarioEditable | null;
}) {
    return (
        <>
            <Head title="Horario de atención" />

            <div className="max-w-2xl p-4">
                <Heading
                    title="Horario de Atención APP"
                    description="Lo que la app usa para saber si se puede pedir."
                />

                {horario !== null && (
                    <p className="mb-4">
                        <Badge
                            variant={
                                horario.abierto_ahora ? 'default' : 'secondary'
                            }
                        >
                            {horario.abierto_ahora
                                ? 'Ahora está abierto'
                                : 'Ahora está cerrado'}
                        </Badge>
                    </p>
                )}

                <Form
                    action="/panel/horario-de-atencion"
                    method="patch"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <Card className="space-y-4 p-6">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="desde">Desde</Label>
                                        <Input
                                            id="desde"
                                            name="desde"
                                            type="time"
                                            defaultValue={horario?.desde ?? '08:00'}
                                            required
                                        />
                                        <InputError message={errors.desde} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="hasta">Hasta</Label>
                                        <Input
                                            id="hasta"
                                            name="hasta"
                                            type="time"
                                            defaultValue={horario?.hasta ?? '20:00'}
                                            required
                                        />
                                        <InputError message={errors.hasta} />
                                    </div>
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    Si «hasta» es menor que «desde», el horario cruza
                                    la medianoche. Está contemplado.
                                </p>
                            </Card>

                            <Card className="space-y-4 p-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="mensaje_horario">
                                        Mensaje fuera de horario
                                    </Label>
                                    <textarea
                                        id="mensaje_horario"
                                        name="mensaje_horario"
                                        rows={2}
                                        defaultValue={horario?.mensaje_horario ?? ''}
                                        required
                                        maxLength={255}
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Lo lee quien abre la app cuando está cerrado.
                                    </p>
                                    <InputError message={errors.mensaje_horario} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="mensaje_confirmacion">
                                        Mensaje al confirmar un pedido
                                    </Label>
                                    <textarea
                                        id="mensaje_confirmacion"
                                        name="mensaje_confirmacion"
                                        rows={2}
                                        defaultValue={
                                            horario?.mensaje_confirmacion ?? ''
                                        }
                                        required
                                        maxLength={255}
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                    />
                                    <InputError
                                        message={errors.mensaje_confirmacion}
                                    />
                                </div>
                            </Card>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    Guardar
                                </Button>
                                {recentlySuccessful && (
                                    <span className="text-muted-foreground text-sm">
                                        Guardado
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

HorarioEdit.layout = {
    breadcrumbs: [
        { title: 'Horario de Atención APP', href: '/panel/horario-de-atencion' },
    ],
};
