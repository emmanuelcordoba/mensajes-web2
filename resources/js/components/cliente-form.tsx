import { Form, Link } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El formulario de un cliente, compartido por el alta y la edición.
|
| ⚠️ El número sólo aparece al editar. Lo asigna `clientes_numero_seq`; si el panel
| dejara escribirlo al crear, la secuencia no avanzaría y un `nextval` posterior chocaría
| con lo escrito a mano. El UNIQUE lo atrapa, pero el alta que falla es la de OTRO
| cliente, que es donde nadie lo va a entender. El panel viejo sí lo pedía: allá el
| número se calculaba en PHP mirando la colección entera (PERF-1).
*/

export type ClienteEditable = {
    id: number;
    numero: number;
    nombre: string | null;
    nombre_mostrado: string;
    direccion: string;
    telefono: string;
    nombre_empleado: string | null;
    nombres: string | null;
    apellidos: string | null;
    de_la_app: boolean;
    cuenta: string | null;
};

export default function ClienteForm({
    cliente,
    action,
    method,
}: {
    cliente?: ClienteEditable;
    action: string;
    method: 'post' | 'patch';
}) {
    return (
        <Form
            action={action}
            method={method}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <Card className="space-y-4 p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="nombre">Nombre</Label>
                            <Input
                                id="nombre"
                                name="nombre"
                                defaultValue={cliente?.nombre ?? ''}
                                required
                                maxLength={255}
                            />
                            {cliente?.de_la_app && (
                                <p className="text-muted-foreground text-xs">
                                    Gana sobre el que escribe la app. Si lo dejás
                                    vacío, vuelve a mostrarse{' '}
                                    <strong>
                                        {[cliente.nombres, cliente.apellidos]
                                            .filter(Boolean)
                                            .join(' ') || 'el de la persona'}
                                    </strong>
                                    .
                                </p>
                            )}
                            <InputError message={errors.nombre} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="direccion">Dirección</Label>
                            <Input
                                id="direccion"
                                name="direccion"
                                defaultValue={cliente?.direccion}
                                required
                                maxLength={255}
                            />
                            <InputError message={errors.direccion} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="telefono">Teléfono</Label>
                                <Input
                                    id="telefono"
                                    name="telefono"
                                    type="tel"
                                    defaultValue={cliente?.telefono}
                                    required
                                    maxLength={20}
                                />
                                <InputError message={errors.telefono} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="nombre_empleado">
                                    Nombre del empleado
                                </Label>
                                <Input
                                    id="nombre_empleado"
                                    name="nombre_empleado"
                                    defaultValue={cliente?.nombre_empleado ?? ''}
                                    maxLength={255}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Quién atiende, si el cliente es un comercio.
                                </p>
                                <InputError message={errors.nombre_empleado} />
                            </div>
                        </div>
                    </Card>

                    {cliente !== undefined && (
                        <Card className="space-y-4 p-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="numero">Número</Label>
                                    <Input
                                        id="numero"
                                        name="numero"
                                        type="number"
                                        min={0}
                                        defaultValue={cliente.numero}
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Lo asigna el sistema al crear. Cambialo sólo
                                        para corregir.
                                    </p>
                                    <InputError message={errors.numero} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Cuenta de la app</Label>
                                    <p className="text-sm">
                                        {cliente.cuenta ?? (
                                            <span className="text-muted-foreground">
                                                Sin cuenta
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        Para moverlo de cuenta hace falta la pantalla
                                        de fusión, que todavía no está.
                                    </p>
                                </div>
                            </div>
                        </Card>
                    )}

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href="/panel/clientes">Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
