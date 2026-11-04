import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';

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
| Los cadetes dados de baja.
|
| Es el botón «Dados de baja» del listado viejo. Mismo criterio que los pedidos eliminados:
| una pantalla aparte y no un filtro del listado, porque las dos listas se usan distinto
| —una es el trabajo del día y la otra es administrativa— y porque las acciones de cada fila
| no son las mismas.
|
| ⚠️ **ACÁ NO SE RESTAURA, y es la diferencia con los pedidos eliminados.** Para un cadete
| dado de baja la baja lógica ya pasó: ofrecerla de nuevo no haría nada. Lo único que falta
| es la otra mitad —sacar la fila—, y eso no se deshace. Por eso la confirmación pide
| escribir el número de móvil, como la pantalla de Corrección de datos del sistema viejo.
*/

type Arrastra = {
    pedidos: number;
    mensajes: number;
    actividades: number;
    cobranza: number;
    postulacion: boolean;
    cuenta: string | null;
};

type CadeteDadoDeBaja = {
    id: number;
    numero_movil: number;
    nombre: string;
    baja: string | null;
    cuenta: { email: string; de_baja: boolean } | null;
    arrastra: Arrastra;
    borrado: { permitido: boolean; motivo: string | null };
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export default function CadetesDadosDeBaja({
    cadetes,
}: {
    cadetes: Paginado<CadeteDadoDeBaja>;
}) {
    const [borrando, setBorrando] = useState<CadeteDadoDeBaja | null>(null);

    return (
        <>
            <Head title="Cadetes dados de baja" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Cadetes dados de baja
                    </h1>

                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm">
                            {cadetes.total === 0
                                ? 'Ninguno'
                                : `${cadetes.from}–${cadetes.to} de ${cadetes.total}`}
                        </span>
                        <Button asChild variant="secondary" size="sm">
                            <Link href="/panel/cadetes">Volver al listado</Link>
                        </Button>
                    </div>
                </div>

                <Card className="space-y-2 p-4 text-sm">
                    <p>
                        Un cadete dado de baja no aparece en el listado ni en la cola, y
                        conserva todo: su legajo, su actividad y sus pedidos. Desde acá se
                        lo puede <strong>borrar definitivamente</strong>, que es sacar la
                        fila de verdad.
                    </p>
                    <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                        <li>
                            <strong>Acá el único borrado es el definitivo.</strong> La baja
                            lógica ya pasó: ofrecerla de nuevo no haría nada.
                        </li>
                        <li>
                            Borra el cadete, su cuenta, su legajo con los documentos de
                            identidad, su actividad, sus mensajes y sus movimientos de
                            cobranza. <strong>Los pedidos no se borran</strong>: son de los
                            clientes, y quedan sin cadete.
                        </li>
                        <li>
                            No se deshace. Queda un registro en el servidor con todo lo que
                            se borró, y la confirmación pide escribir el número de móvil.
                        </li>
                    </ul>
                </Card>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Móvil</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Dado de baja</th>
                                    <th className="px-4 py-3 font-medium">Cuenta</th>
                                    <th className="px-4 py-3 font-medium">Pedidos</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {cadetes.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            No hay cadetes dados de baja.
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
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cadete.baja ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {/* ⚠️ Lo normal es que la cuenta esté dada de
                                                baja con el cadete. Que siga activa es la
                                                señal de que algo más la está usando, y de
                                                que el borrado se va a negar: conviene verlo
                                                antes de apretar. */}
                                            {cadete.cuenta === null ? (
                                                <span className="text-muted-foreground italic">
                                                    sin cuenta
                                                </span>
                                            ) : (
                                                <span
                                                    className="flex items-center gap-2"
                                                    title={cadete.cuenta.email}
                                                >
                                                    <span className="block max-w-44 truncate">
                                                        {cadete.cuenta.email}
                                                    </span>
                                                    {!cadete.cuenta.de_baja && (
                                                        <Badge variant="destructive">
                                                            activa
                                                        </Badge>
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {cadete.arrastra.pedidos}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                {cadete.borrado.permitido ? (
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() => setBorrando(cadete)}
                                                    >
                                                        Borrado definitivo
                                                    </Button>
                                                ) : (
                                                    /* El motivo entero en lugar de un botón
                                                       que iba a fallar, como en la baja del
                                                       listado. */
                                                    cadete.borrado.motivo !== null && (
                                                        <span className="text-muted-foreground max-w-72 text-right text-xs whitespace-normal">
                                                            {cadete.borrado.motivo}
                                                        </span>
                                                    )
                                                )}
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

            <DialogoDeBorrado
                cadete={borrando}
                cerrar={() => setBorrando(null)}
            />
        </>
    );
}

/**
 * La confirmación del borrado definitivo.
 *
 * ⚠️ **Pide escribir el número de móvil, y no es un adorno.** Es lo único del panel que
 * saca filas de verdad y no se deshace. El sistema viejo pide lo mismo, en una pantalla
 * entera de Corrección de datos que además mide el impacto; acá el impacto viaja con la
 * fila y la pregunta vive en este diálogo.
 *
 * ⚠️ El servidor vuelve a comparar lo escrito. Lo de acá es para que el botón no esté
 * disponible antes de tiempo, no para decidir: ver `CadeteController::borradoDefinitivo`.
 */
function DialogoDeBorrado({
    cadete,
    cerrar,
}: {
    cadete: CadeteDadoDeBaja | null;
    cerrar: () => void;
}) {
    const [escrito, setEscrito] = useState('');

    const coincide =
        cadete !== null && escrito.trim() === String(cadete.numero_movil);

    return (
        <Dialog
            open={cadete !== null}
            onOpenChange={(abierto) => {
                if (!abierto) {
                    setEscrito('');
                    cerrar();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Borrar el móvil {cadete?.numero_movil}
                    </DialogTitle>
                    <DialogDescription>
                        {cadete?.nombre} sale del padrón de verdad. No se deshace.
                    </DialogDescription>
                </DialogHeader>

                {cadete !== null && (
                    <>
                        <ul className="space-y-1 text-sm">
                            <li>
                                Se borran{' '}
                                <strong>{cadete.arrastra.mensajes} mensajes</strong>,{' '}
                                <strong>
                                    {cadete.arrastra.actividades} tramos de actividad
                                </strong>{' '}
                                y{' '}
                                <strong>
                                    {cadete.arrastra.cobranza} movimientos de cobranza
                                </strong>
                                .
                            </li>
                            {cadete.arrastra.postulacion && (
                                <li>
                                    Se borra su <strong>postulación</strong>, con los
                                    documentos de identidad.
                                </li>
                            )}
                            {cadete.arrastra.cuenta !== null && (
                                <li>
                                    Se borra su cuenta{' '}
                                    <strong>{cadete.arrastra.cuenta}</strong>.
                                </li>
                            )}
                            <li className="text-muted-foreground">
                                Sus {cadete.arrastra.pedidos} pedidos{' '}
                                <strong>no se borran</strong>: son de los clientes, y
                                quedan sin cadete.
                            </li>
                        </ul>

                        <Form
                            action={`/panel/cadetes/${cadete.id}/borrado-definitivo`}
                            method="delete"
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                setEscrito('');
                                cerrar();
                            }}
                            className="space-y-4"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="confirmacion">
                                            Escribí el número de móvil para confirmar
                                        </Label>
                                        <Input
                                            id="confirmacion"
                                            name="confirmacion"
                                            autoComplete="off"
                                            inputMode="numeric"
                                            value={escrito}
                                            onChange={(e) => setEscrito(e.target.value)}
                                            placeholder={String(cadete.numero_movil)}
                                        />
                                    </div>

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
                                            disabled={processing || !coincide}
                                        >
                                            Borrar definitivamente
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

CadetesDadosDeBaja.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Dados de baja', href: '/panel/cadetes/dados-de-baja' },
    ],
};
