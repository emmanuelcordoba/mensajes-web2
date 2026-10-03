import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Los cadetes.
|
| Es la pantalla del menú lateral viejo, con sus mismas acciones: editar, ponerlo en la cola
| o sacarlo, bloquearlo y darlo de baja.
|
| ⚠️ Cada acción se dibuja según lo que contesta el servidor, que es lo mismo que el
| controlador aplica. Cuando una no se puede, no hay botón: el panel viejo dibujaba el de
| cambiar estado siempre, y sobre un cadete bloqueado no hacía nada ni avisaba. Lo que
| explica la ausencia está en la fila —«Bloqueado» en el estado, «sin cuenta» en el email—,
| y para la baja, que es la que mueve plata, se muestra el motivo entero.
*/

type Decision = { permitido: boolean; motivo: string | null };

type CadeteEnLista = {
    id: number;
    numero_movil: number;
    nombre: string;
    email: string | null;
    telefono: string;
    vehiculo: string;
    estado: string;
    trabajando: boolean;
    bloqueo: string | null;
    pedidos: number;
    mensajes: number;
    cambiar_estado: Decision;
    bloquear: Decision;
    baja: Decision;
};

type Motivo = { codigo: string; descripcion: string; pide_mensaje: boolean };

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export default function CadetesIndex({
    cadetes,
    filtros,
    motivos,
}: {
    cadetes: Paginado<CadeteEnLista>;
    filtros: { buscar: string };
    motivos: Motivo[];
}) {
    const [bloqueando, setBloqueando] = useState<CadeteEnLista | null>(null);

    return (
        <>
            <Head title="Cadetes" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">Cadetes</h1>

                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm">
                            {cadetes.from ?? 0}–{cadetes.to ?? 0} de {cadetes.total}
                        </span>
                        <Button asChild size="sm">
                            <Link href="/panel/cadetes/create">Nuevo cadete</Link>
                        </Button>
                    </div>
                </div>

                <Card className="p-4">
                    <form
                        method="get"
                        action="/panel/cadetes"
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const datos = new FormData(e.currentTarget);
                            router.get('/panel/cadetes', {
                                buscar: String(datos.get('buscar') ?? ''),
                            });
                        }}
                    >
                        <label className="grid flex-1 gap-1 text-sm">
                            <span className="text-muted-foreground">
                                Nombre, apellido o N° de móvil
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

                        {filtros.buscar !== '' && (
                            <Button asChild variant="ghost">
                                <Link href="/panel/cadetes">Limpiar</Link>
                            </Button>
                        )}
                    </form>
                </Card>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Móvil</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Email</th>
                                    <th className="px-4 py-3 font-medium">Teléfono</th>
                                    <th className="px-4 py-3 font-medium">Vehículo</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {cadetes.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Ningún cadete con ese criterio.
                                        </td>
                                    </tr>
                                )}

                                {cadetes.data.map((cadete) => (
                                    <tr key={cadete.id} className="border-b last:border-0">
                                        <td className="px-4 py-3 tabular-nums">
                                            {cadete.numero_movil}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cadete.nombre}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {/* Cortado: hay emails de 40 caracteres y la
                                                columna se come el ancho de las acciones.
                                                Entero en el título, y entero al editar. */}
                                            <span
                                                className="block max-w-44 truncate"
                                                title={cadete.email ?? undefined}
                                            >
                                                {cadete.email ?? (
                                                    <span className="italic">sin cuenta</span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cadete.telefono}
                                        </td>
                                        <td className="px-4 py-3">{cadete.vehiculo}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-1">
                                                <Badge
                                                    variant={
                                                        cadete.trabajando
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {cadete.estado}
                                                </Badge>
                                                {cadete.bloqueo !== null && (
                                                    <Badge
                                                        variant="destructive"
                                                        title={cadete.bloqueo}
                                                    >
                                                        Bloqueado
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button asChild size="sm" variant="outline">
                                                    <Link
                                                        href={`/panel/cadetes/${cadete.id}/edit`}
                                                    >
                                                        Editar
                                                    </Link>
                                                </Button>

                                                {cadete.cambiar_estado.permitido && (
                                                    <Form
                                                        action={`/panel/cadetes/${cadete.id}/estado`}
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
                                                                {cadete.trabajando
                                                                    ? 'Sacar de cola'
                                                                    : 'A la cola'}
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}

                                                {cadete.bloquear.permitido &&
                                                    (cadete.bloqueo === null ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setBloqueando(cadete)
                                                            }
                                                        >
                                                            Bloquear
                                                        </Button>
                                                    ) : (
                                                        <Form
                                                            action={`/panel/cadetes/${cadete.id}/bloqueo`}
                                                            method="delete"
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({ processing }) => (
                                                                <Button
                                                                    type="submit"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={processing}
                                                                >
                                                                    Desbloquear
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    ))}

                                                <Baja cadete={cadete} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {cadetes.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {cadetes.links.map((link, i) =>
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

            <DialogoDeBloqueo
                cadete={bloqueando}
                motivos={motivos}
                cerrar={() => setBloqueando(null)}
            />
        </>
    );
}

/**
 * La baja, o el motivo por el que no se puede.
 *
 * ⚠️ El motivo se muestra entero, en lugar de un botón que iba a fallar: la única negativa
 * que no es de rol es tener la cobranza abierta, y eso es plata que alguien tiene que ir a
 * cerrar. El panel viejo lo contestaba recién después de apretar.
 */
function Baja({ cadete }: { cadete: CadeteEnLista }) {
    if (!cadete.baja.permitido) {
        return cadete.baja.motivo === null ? null : (
            <span className="text-muted-foreground max-w-56 text-right text-xs whitespace-normal">
                {cadete.baja.motivo}
            </span>
        );
    }

    return (
        <Form
            action={`/panel/cadetes/${cadete.id}`}
            method="delete"
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(
                    `Se dan de baja el móvil ${cadete.numero_movil} (${cadete.nombre}), su cuenta, ` +
                        `sus ${cadete.mensajes} mensajes y su postulación, con los documentos de identidad.\n\n` +
                        `Sus ${cadete.pedidos} pedidos se conservan en el historial.\n\n¿Seguir?`,
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
    );
}

/**
 * El motivo del bloqueo.
 *
 * Es el modal del panel viejo, con la misma regla: el mensaje se pide sólo cuando el motivo
 * es «otro». Allá eso lo decidía el JavaScript del navegador y el servidor lo repetía;
 * ahora quién pide mensaje lo dice el servidor, en la misma lista de motivos.
 */
function DialogoDeBloqueo({
    cadete,
    motivos,
    cerrar,
}: {
    cadete: CadeteEnLista | null;
    motivos: Motivo[];
    cerrar: () => void;
}) {
    const [motivo, setMotivo] = useState('');

    const pideMensaje = motivos.some(
        (uno) => uno.codigo === motivo && uno.pide_mensaje,
    );

    return (
        <Dialog
            open={cadete !== null}
            onOpenChange={(abierto) => {
                if (!abierto) {
                    setMotivo('');
                    cerrar();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Bloquear el móvil {cadete?.numero_movil}
                    </DialogTitle>
                    <DialogDescription>
                        {cadete?.nombre} no va a poder entrar a la app ni a la cola
                        hasta que lo desbloqueen.
                    </DialogDescription>
                </DialogHeader>

                {cadete !== null && (
                    <Form
                        action={`/panel/cadetes/${cadete.id}/bloqueo`}
                        method="patch"
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            setMotivo('');
                            cerrar();
                        }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="motivo">Motivo</Label>
                                    <select
                                        id="motivo"
                                        name="motivo"
                                        required
                                        value={motivo}
                                        onChange={(e) => setMotivo(e.target.value)}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        <option value="">Elegí un motivo</option>
                                        {motivos.map((uno) => (
                                            <option key={uno.codigo} value={uno.codigo}>
                                                {uno.descripcion}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.motivo} />
                                </div>

                                {pideMensaje && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="mensaje">Mensaje</Label>
                                        <textarea
                                            id="mensaje"
                                            name="mensaje"
                                            rows={2}
                                            maxLength={120}
                                            required
                                            className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Es lo que va a leer el cadete. Hasta 120
                                            caracteres.
                                        </p>
                                        <InputError message={errors.mensaje} />
                                    </div>
                                )}

                                <div className="flex items-center justify-end gap-3">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={cerrar}
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Bloquear
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

CadetesIndex.layout = {
    breadcrumbs: [{ title: 'Cadetes', href: '/panel/cadetes' }],
};
