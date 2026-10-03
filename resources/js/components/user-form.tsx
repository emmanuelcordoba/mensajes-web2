import { Form, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El formulario de una cuenta del panel, compartido por el alta y la edición.
|
| Uno solo para los dos porque las diferencias son dos —la contraseña es obligatoria sólo
| al crear, y al editar vienen valores— y tenerlas juntas es lo que hace que no se
| separen. El servidor valida lo mismo en los dos caminos: ver GuardarUsuarioRequest.
*/

export type RolDisponible = {
    id: number;
    nombre: string;
    /** Si es el rol de comercio, el único que mira un cliente. */
    es_comercio: boolean;
};

export type CuentaEditable = {
    id: number;
    name: string;
    email: string;
    iniciales: string | null;
    color: string | null;
    rol_id: number | null;
    cliente_restringido_id: number | null;
    comercio: string | null;
};

type Comercio = { id: number; nombre: string };

export default function UserForm({
    roles,
    usuario,
    action,
    method,
}: {
    roles: RolDisponible[];
    usuario?: CuentaEditable;
    action: string;
    method: 'post' | 'patch';
}) {
    const [rolId, setRolId] = useState<number | null>(usuario?.rol_id ?? null);

    const esComercio = roles.some(
        (rol) => rol.id === rolId && rol.es_comercio,
    );

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
                            <Label htmlFor="name">Nombre</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={usuario?.name}
                                required
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={usuario?.email}
                                required
                                autoComplete="off"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="password">Contraseña</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required={usuario === undefined}
                                    autoComplete="new-password"
                                />
                                {usuario !== undefined && (
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
                                    required={usuario === undefined}
                                    autoComplete="new-password"
                                />
                            </div>
                        </div>
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="rol_id">Rol</Label>
                            <select
                                id="rol_id"
                                name="rol_id"
                                required
                                value={rolId ?? ''}
                                onChange={(e) =>
                                    setRolId(
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Elegí un rol</option>
                                {roles.map((rol) => (
                                    <option key={rol.id} value={rol.id}>
                                        {rol.nombre}
                                    </option>
                                ))}
                            </select>
                            {/* ⚠️ El rol de administrador sólo aparece acá si quien mira
                                puede nombrarlo. El validador lo rechaza igual —SEC-4—,
                                pero ofrecer algo que después se niega es el botón que
                                siempre falla. */}
                            <InputError message={errors.rol_id} />
                        </div>

                        {esComercio && (
                            <ElegirComercio
                                nombreInicial={usuario?.comercio ?? null}
                                idInicial={usuario?.cliente_restringido_id ?? null}
                                error={errors.cliente_restringido_id}
                            />
                        )}
                    </Card>

                    <Card className="space-y-4 p-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="iniciales">Iniciales</Label>
                                <Input
                                    id="iniciales"
                                    name="iniciales"
                                    maxLength={2}
                                    defaultValue={usuario?.iniciales ?? ''}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Dos letras, para el avatar.
                                </p>
                                <InputError message={errors.iniciales} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="color">Color</Label>
                                <Input
                                    id="color"
                                    name="color"
                                    type="color"
                                    className="h-9 w-20 p-1"
                                    defaultValue={usuario?.color ?? '#64748b'}
                                />
                                <InputError message={errors.color} />
                            </div>
                        </div>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href="/panel/users">Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * El comercio cuyos pedidos ve una cuenta `restringido`.
 *
 * Se busca contra el servidor en lugar de mandar los 11.057 clientes en la página, y los
 * resultados traen el número además del nombre: hay nombres repetidos entre los clientes
 * y una lista con tres «Dasani SAS» no dice a cuál se apunta.
 */
function ElegirComercio({
    nombreInicial,
    idInicial,
    error,
}: {
    nombreInicial: string | null;
    idInicial: number | null;
    error?: string;
}) {
    const [elegido, setElegido] = useState<Comercio | null>(
        idInicial !== null && nombreInicial !== null
            ? { id: idInicial, nombre: nombreInicial }
            : null,
    );
    const [buscado, setBuscado] = useState('');
    const [encontrados, setEncontrados] = useState<Comercio[]>([]);

    useEffect(() => {
        if (buscado.trim() === '') {
            setEncontrados([]);

            return;
        }

        // Un respiro antes de preguntar: si no, se dispara una consulta por tecla.
        const espera = setTimeout(() => {
            void fetch(
                `/panel/users/comercios?q=${encodeURIComponent(buscado)}`,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => (r.ok ? r.json() : []))
                .then((datos: Comercio[]) => setEncontrados(datos))
                .catch(() => setEncontrados([]));
        }, 300);

        return () => clearTimeout(espera);
    }, [buscado]);

    return (
        <div className="grid gap-2">
            <Label htmlFor="buscar-comercio">Comercio que ve</Label>

            <input
                type="hidden"
                name="cliente_restringido_id"
                value={elegido?.id ?? ''}
            />

            {elegido !== null ? (
                <div className="flex items-center gap-3">
                    <span className="text-sm font-medium">{elegido.nombre}</span>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setElegido(null)}
                    >
                        Cambiar
                    </Button>
                </div>
            ) : (
                <>
                    <Input
                        id="buscar-comercio"
                        value={buscado}
                        onChange={(e) => setBuscado(e.target.value)}
                        placeholder="Buscá por número o por nombre"
                        autoComplete="off"
                    />
                    {encontrados.length > 0 && (
                        <ul className="divide-y rounded-md border">
                            {encontrados.map((comercio) => (
                                <li key={comercio.id}>
                                    <button
                                        type="button"
                                        className="hover:bg-muted w-full px-3 py-2 text-left text-sm"
                                        onClick={() => {
                                            setElegido(comercio);
                                            setBuscado('');
                                        }}
                                    >
                                        {comercio.nombre}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}

            <p className="text-muted-foreground text-xs">
                Los pedidos que esta cuenta puede ver son los de este comercio.
            </p>

            <InputError message={error} />
        </div>
    );
}
