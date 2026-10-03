import { Form, Link } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El formulario de un cadete, compartido por el alta y la edición.
|
| ⚠️ UN CADETE NO ES UNA FILA: `cadetes.user_id` es NOT NULL y todo cadete tiene la cuenta
| con la que entra a la app. Por eso el alta pide también email y contraseña, y por eso del
| otro lado la escribe un servicio y no el controlador.
|
| ⚠️ Lo que este formulario NO tiene, y es a propósito:
|
| - **El estado.** Se cambia desde el listado, como en el panel viejo: poner a alguien en la
|   cola es una acción, no un campo que se guarda junto con su teléfono.
| - **La modalidad de cobranza.** Cambiarla a mitad de una cobranza mueve plata entre la
|   deuda y el saldo, y eso es de las pantallas de cobranzas. Ver PANEL-8.
| - **La foto de perfil.** El panel viejo la guardaba en base64 dentro de la fila del
|   usuario; en este sistema las fotos todavía no están portadas, ni acá ni en la pantalla
|   de cuentas.
*/

export type CadeteEditable = {
    id: number;
    numero_movil: number;
    nombre_completo: string;
    nombres: string;
    apellidos: string;
    direccion: string;
    telefono: string;
    fecha_nacimiento: string;
    dni: number | null;
    tipo_vehiculo: string;
    observaciones: string | null;
    tiene_monto_semanal_personal: boolean;
    estado: string;
    modalidad_cobranza: string;
    /** El email de la cuenta, o null si está dada de baja. */
    cuenta: string | null;
};

export default function CadeteForm({
    cadete,
    vehiculos,
    siguienteMovil,
    action,
    method,
}: {
    cadete?: CadeteEditable;
    vehiculos: Record<string, string>;
    siguienteMovil?: number;
    action: string;
    method: 'post' | 'patch';
}) {
    // Al crear siempre hay cuenta: se crea junto con el cadete.
    const conCuenta = cadete === undefined || cadete.cuenta !== null;

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
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="apellidos">Apellidos</Label>
                                <Input
                                    id="apellidos"
                                    name="apellidos"
                                    defaultValue={cadete?.apellidos}
                                    required
                                    maxLength={60}
                                />
                                <InputError message={errors.apellidos} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="nombres">Nombres</Label>
                                <Input
                                    id="nombres"
                                    name="nombres"
                                    defaultValue={cadete?.nombres}
                                    required
                                    maxLength={60}
                                />
                                <InputError message={errors.nombres} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="dni">DNI</Label>
                                <Input
                                    id="dni"
                                    name="dni"
                                    inputMode="numeric"
                                    defaultValue={cadete?.dni ?? ''}
                                />
                                <p className="text-muted-foreground text-xs">
                                    7 u 8 dígitos, sin puntos. Puede quedar vacío.
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
                                    defaultValue={cadete?.fecha_nacimiento}
                                    required
                                />
                                {/* ⚠️ La columna no tiene CHECK de rango y acepta el año
                                    190: hay 32 cadetes con fechas imposibles (DATA-10).
                                    Esta pantalla es por donde se arreglan. */}
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
                                    defaultValue={cadete?.telefono}
                                    required
                                    maxLength={20}
                                />
                                <InputError message={errors.telefono} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="direccion">Dirección</Label>
                                <Input
                                    id="direccion"
                                    name="direccion"
                                    defaultValue={cadete?.direccion}
                                    required
                                    maxLength={255}
                                />
                                <InputError message={errors.direccion} />
                            </div>
                        </div>
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="numero_movil">N° de móvil</Label>
                                <Input
                                    id="numero_movil"
                                    name="numero_movil"
                                    type="number"
                                    min={1}
                                    defaultValue={
                                        cadete?.numero_movil ?? siguienteMovil ?? ''
                                    }
                                    required
                                />
                                {/* ⚠️ El número sugerido es el último más uno, y nada más
                                    que una sugerencia: en la práctica se recicla el del
                                    cadete que se va, así que los números vuelven para
                                    atrás. No hay secuencia y no debe haberla (PANEL-5). */}
                                <p className="text-muted-foreground text-xs">
                                    Es con lo que lo nombra la central.
                                </p>
                                <InputError message={errors.numero_movil} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tipo_vehiculo">Vehículo</Label>
                                <select
                                    id="tipo_vehiculo"
                                    name="tipo_vehiculo"
                                    required
                                    defaultValue={cadete?.tipo_vehiculo ?? ''}
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
                        </div>

                        <div className="flex items-start gap-3">
                            {/* El hidden es el que viaja cuando queda sin tildar: un
                                checkbox sin marcar no manda nada. */}
                            <input
                                type="hidden"
                                name="tiene_monto_semanal_personal"
                                value="0"
                            />
                            <input
                                type="checkbox"
                                id="tiene_monto_semanal_personal"
                                name="tiene_monto_semanal_personal"
                                value="1"
                                defaultChecked={
                                    cadete?.tiene_monto_semanal_personal ?? false
                                }
                                className="border-input mt-0.5 size-4 rounded border"
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="tiene_monto_semanal_personal">
                                    Cuota semanal propia
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Si no, cobra el monto general de su vehículo.
                                </p>
                            </div>
                        </div>
                        <InputError message={errors.tiene_monto_semanal_personal} />

                        {cadete !== undefined && (
                            <p className="text-muted-foreground border-t pt-4 text-xs">
                                Hoy está <strong>{cadete.estado}</strong> y cobra por{' '}
                                <strong>
                                    {cadete.modalidad_cobranza.toLowerCase()}
                                </strong>
                                . El estado se cambia desde el listado; la modalidad,
                                desde cobranzas.
                            </p>
                        )}
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-1">
                            <h2 className="text-sm font-medium">La cuenta de la app</h2>
                            <p className="text-muted-foreground text-xs">
                                {cadete === undefined
                                    ? 'Se crea junto con el cadete, y es con la que entra a la app.'
                                    : 'Es con la que entra a la app.'}
                            </p>
                        </div>

                        {conCuenta ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={cadete?.cuenta ?? ''}
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Contraseña</Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        required={cadete === undefined}
                                        autoComplete="new-password"
                                    />
                                    {cadete !== undefined && (
                                        <p className="text-muted-foreground text-xs">
                                            Dejala en blanco para no cambiarla.
                                        </p>
                                    )}
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Repetir contraseña
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        required={cadete === undefined}
                                        autoComplete="new-password"
                                    />
                                </div>
                            </div>
                        ) : (
                            /* ⚠️ Pasa aunque `cadetes.user_id` sea NOT NULL: `User` usa
                               bajas lógicas, así que una cuenta dada de baja deja al
                               cadete sin dónde guardar el email ni la contraseña. */
                            <p className="text-muted-foreground text-sm">
                                Este cadete no tiene cuenta activa, así que no hay email
                                ni contraseña que editar. Para reponerla hace falta
                                restaurar la cuenta.
                            </p>
                        )}
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="observaciones">Observaciones</Label>
                            <textarea
                                id="observaciones"
                                name="observaciones"
                                rows={3}
                                maxLength={2000}
                                defaultValue={cadete?.observaciones ?? ''}
                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                            />
                            <InputError message={errors.observaciones} />
                        </div>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href="/panel/cadetes">Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
