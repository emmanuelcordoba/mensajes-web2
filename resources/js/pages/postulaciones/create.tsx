import { Form, Head, Link } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Cargar una postulación desde el panel, que es lo que hace la oficina cuando alguien se
| presenta en persona. La otra puerta es la app, que no está portada.
|
| ⚠️ Las cuatro imágenes se suben como archivos y las valida el servidor. El formulario
| viejo mandaba un campo de archivo y un campo oculto con el base64 que completaba el
| JavaScript, y validaba el de archivo mientras guardaba el oculto: si ese JS fallaba, la
| postulación se guardaba sin ninguna imagen.
|
| ⚠️ Y no se pide contraseña. El formulario viejo la pedía y la tabla guardaba una
| contraseña en texto plano por postulación; la columna no existe más. La cuenta se crea al
| contratar.
*/

export default function PostulacionesCreate({
    vehiculos,
    preguntas,
    documentos,
}: {
    vehiculos: Record<string, string>;
    preguntas: Record<string, string>;
    documentos: string[];
}) {
    const etiquetas: Record<string, string> = {
        foto: 'Foto de perfil',
        dni_frente: 'DNI, frente',
        dni_dorso: 'DNI, dorso',
        boleta_de_servicio: 'Boleta de servicio',
    };

    return (
        <>
            <Head title="Nueva postulación" />

            <div className="max-w-3xl p-4">
                <Heading
                    title="Nueva postulación"
                    description="Queda pendiente hasta que alguien decida contratarla o rechazarla."
                />

                <Form
                    action="/panel/cadetes/postulaciones"
                    method="post"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className="space-y-4 p-6">
                                <h2 className="text-sm font-medium">La persona</h2>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="nombres">Nombres</Label>
                                        <Input
                                            id="nombres"
                                            name="nombres"
                                            required
                                            maxLength={60}
                                        />
                                        <InputError message={errors.nombres} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="apellidos">Apellidos</Label>
                                        <Input
                                            id="apellidos"
                                            name="apellidos"
                                            required
                                            maxLength={60}
                                        />
                                        <InputError message={errors.apellidos} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="dni">DNI</Label>
                                        <Input
                                            id="dni"
                                            name="dni"
                                            inputMode="numeric"
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            7 u 8 dígitos, sin puntos.
                                        </p>
                                        <InputError message={errors.dni} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="fecha_nacimiento">
                                            Fecha de nacimiento
                                        </Label>
                                        <Input
                                            id="fecha_nacimiento"
                                            name="fecha_nacimiento"
                                            type="date"
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Entre 16 y 90 años.
                                        </p>
                                        <InputError message={errors.fecha_nacimiento} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="telefono">Teléfono</Label>
                                        <Input
                                            id="telefono"
                                            name="telefono"
                                            type="tel"
                                            required
                                            maxLength={20}
                                        />
                                        <InputError message={errors.telefono} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            autoComplete="off"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Va a ser su usuario en la app.
                                        </p>
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="direccion">Dirección</Label>
                                        <Input
                                            id="direccion"
                                            name="direccion"
                                            required
                                            maxLength={255}
                                        />
                                        <InputError message={errors.direccion} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tipo_vehiculo">Vehículo</Label>
                                        <select
                                            id="tipo_vehiculo"
                                            name="tipo_vehiculo"
                                            required
                                            defaultValue=""
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        >
                                            <option value="">Elegí un vehículo</option>
                                            {Object.entries(vehiculos).map(
                                                ([codigo, nombre]) => (
                                                    <option key={codigo} value={codigo}>
                                                        {nombre}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError message={errors.tipo_vehiculo} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="recomendado_por">
                                            Recomendado por
                                        </Label>
                                        <Input
                                            id="recomendado_por"
                                            name="recomendado_por"
                                            maxLength={100}
                                        />
                                        <InputError message={errors.recomendado_por} />
                                    </div>
                                </div>
                            </Card>

                            <Card className="space-y-4 p-6">
                                <h2 className="text-sm font-medium">El cuestionario</h2>

                                <div className="space-y-4">
                                    {Object.entries(preguntas).map(([campo, texto]) => (
                                        <div
                                            key={campo}
                                            className="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                                        >
                                            <span className="text-sm">{texto}</span>
                                            <div className="flex items-center gap-4">
                                                {[
                                                    ['S', 'Sí'],
                                                    ['N', 'No'],
                                                ].map(([valor, etiqueta]) => (
                                                    <label
                                                        key={valor}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name={campo}
                                                            value={valor}
                                                            required
                                                            className="border-input size-4"
                                                        />
                                                        {etiqueta}
                                                    </label>
                                                ))}
                                            </div>
                                            <InputError message={errors[campo]} />
                                        </div>
                                    ))}
                                </div>
                            </Card>

                            <Card className="space-y-4 p-6">
                                <div className="grid gap-1">
                                    <h2 className="text-sm font-medium">
                                        Los documentos
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        Los cuatro, en imagen. Quedan guardados en un
                                        disco privado: para contratar hacen falta el DNI
                                        y la boleta.
                                    </p>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {documentos.map((tipo) => (
                                        <div key={tipo} className="grid gap-2">
                                            <Label htmlFor={tipo}>
                                                {etiquetas[tipo] ?? tipo}
                                            </Label>
                                            <Input
                                                id={tipo}
                                                name={tipo}
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                required
                                                className="file:text-foreground file:mr-3 file:border-0 file:bg-transparent file:text-sm"
                                            />
                                            <InputError message={errors[tipo]} />
                                        </div>
                                    ))}
                                </div>
                            </Card>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    Guardar
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href="/panel/cadetes/postulaciones">
                                        Cancelar
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

PostulacionesCreate.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Postulaciones', href: '/panel/cadetes/postulaciones' },
        { title: 'Nueva', href: '/panel/cadetes/postulaciones/create' },
    ],
};
