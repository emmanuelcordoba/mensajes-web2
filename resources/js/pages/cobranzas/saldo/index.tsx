import { Form, Head, Link, router } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Cobranzas · Saldo.
|
| El cadete carga plata y cada pedido se la descuenta. Es la modalidad que la empresa usa
| de verdad: 13.412 movimientos contra 15 cadetes que alguna vez abrieron un ciclo de
| cobranza semanal.
|
| ⚠️ El listado trae también a los de modalidad semanal, detrás de un filtro, y no es un
| capricho: pasar un cadete de una modalidad a la otra no se podía hacer desde ninguna
| pantalla del sistema nuevo —el alta la pone en «Semanal» y ahí quedaba, PANEL-8—, y acá
| es donde esa decisión tiene sentido. Lo que se abre por omisión son los de saldo.
|
| ⚠️ **Esas tres vistas son lo que permite cambiar la modalidad con plata en el medio.**
| En el sistema viejo cada listado filtraba duro por su modalidad, así que un cadete que
| cambiaba con saldo a favor se llevaba esa plata fuera de todo listado y nadie la volvía
| a ver. Acá no se va a ninguna parte: la fila muestra las dos columnas cuando hay algo
| dormido del otro lado, «Movimientos» sigue abierto, y el cambio avisa qué queda.
|
| ⚠️ Los montos llegan como texto y se muestran como texto. No pasan por Number en ningún
| punto: es la plata de una persona.
*/

type EnLista = {
    id: number;
    movil: number;
    nombre: string;
    modalidad: string;
    por_saldo: boolean;
    saldo: string;
    deuda: string;
    cambio: { permitido: boolean; motivo: string | null };
    pasaria_a: string;
    aviso: string | null;
    tiene_saldo: boolean;
    tiene_deuda: boolean;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

const MODALIDADES: Record<string, string> = {
    saldo: 'Por saldo',
    semanal: 'Por semana',
    todas: 'Todos',
};

export default function CobranzaSaldoIndex({
    cadetes,
    filtros,
    saldo_minimo,
    porcentaje,
}: {
    cadetes: Paginado<EnLista>;
    filtros: { buscar: string; modalidad: string };
    /** Null si falta alguna de las dos configuraciones con que se calcula. */
    saldo_minimo: number | null;
    porcentaje: { id: number; titulo: string; valor: string | null } | null;
}) {
    return (
        <>
            <Head title="Cobranza por saldo" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Cobranzas · Saldo
                    </h1>
                    <span className="text-muted-foreground text-sm">
                        {cadetes.from ?? 0}–{cadetes.to ?? 0} de {cadetes.total}
                    </span>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="space-y-4 p-4 lg:col-span-2">
                        <form
                            method="get"
                            action="/panel/cadetes/cobranzas/saldo"
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                const datos = new FormData(e.currentTarget);
                                router.get('/panel/cadetes/cobranzas/saldo', {
                                    modalidad: String(datos.get('modalidad') ?? ''),
                                    buscar: String(datos.get('buscar') ?? ''),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                <span className="text-muted-foreground">Mostrar</span>
                                <select
                                    name="modalidad"
                                    defaultValue={filtros.modalidad}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {Object.entries(MODALIDADES).map(([valor, texto]) => (
                                        <option key={valor} value={valor}>
                                            {texto}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="grid flex-1 gap-1 text-sm">
                                <span className="text-muted-foreground">
                                    Nombre, apellido o móvil
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

                            {(filtros.buscar !== '' || filtros.modalidad !== 'saldo') && (
                                <Button asChild variant="ghost">
                                    <Link href="/panel/cadetes/cobranzas/saldo">
                                        Limpiar
                                    </Link>
                                </Button>
                            )}
                        </form>
                    </Card>

                    <ElPorcentaje porcentaje={porcentaje} saldoMinimo={saldo_minimo} />
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Móvil</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Cobranza</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Saldo
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {cadetes.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Ningún cadete con ese criterio.
                                        </td>
                                    </tr>
                                )}

                                {cadetes.data.map((cadete) => (
                                    <tr
                                        key={cadete.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 tabular-nums">
                                            {cadete.movil}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cadete.nombre}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    cadete.por_saldo
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {cadete.modalidad}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            <Plata
                                                cadete={cadete}
                                                saldoMinimo={saldo_minimo}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                                                {/* También al semanal que dejó saldo
                                                    dormido: sus movimientos son los que
                                                    explican de dónde salió ese número. */}
                                                {(cadete.por_saldo ||
                                                    cadete.tiene_saldo) && (
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={`/panel/cadetes/cobranzas/saldo/${cadete.id}`}
                                                        >
                                                            Movimientos
                                                        </Link>
                                                    </Button>
                                                )}

                                                <Cambio cadete={cadete} />
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
        </>
    );
}

/**
 * Un saldo, con el aviso de si quedó por debajo del mínimo.
 *
 * ⚠️ La comparación se hace acá sobre un número, y es el único lugar donde el monto pasa
 * por Number: es para pintarlo de un color, no para hacer una cuenta. Lo que se muestra es
 * siempre la cadena que mandó el servidor.
 */
function Monto({ valor, minimo }: { valor: string; minimo: number | null }) {
    const alcanza = minimo === null || Number(valor) >= minimo;

    return (
        <span className={alcanza ? '' : 'text-red-600 dark:text-red-400'}>
            ${valor}
        </span>
    );
}

/**
 * Lo que la fila muestra de plata: lo de su modalidad, y lo que quedó dormido del otro
 * lado si quedó algo.
 *
 * ⚠️ Las dos columnas y no una. Mientras el cambio de modalidad estuvo prohibido con plata
 * en el medio, una sola alcanzaba: el semanal nunca tenía saldo. Ahora puede tenerlo, y
 * esta segunda línea es la que hace que esa plata siga a la vista en lugar de quedar en
 * una columna que la pantalla no dibuja.
 */
function Plata({
    cadete,
    saldoMinimo,
}: {
    cadete: EnLista;
    saldoMinimo: number | null;
}) {
    const dormido = cadete.por_saldo
        ? cadete.tiene_deuda && `deuda $${cadete.deuda}`
        : cadete.tiene_saldo && `saldo $${cadete.saldo}`;

    return (
        <>
            {cadete.por_saldo ? (
                <Monto valor={cadete.saldo} minimo={saldoMinimo} />
            ) : (
                <span className="text-muted-foreground">
                    deuda ${cadete.deuda}
                </span>
            )}

            {dormido && (
                <div
                    className="text-muted-foreground text-xs"
                    title="Plata que quedó de la otra modalidad de cobranza."
                >
                    {dormido}
                </div>
            )}
        </>
    );
}

/**
 * Pasar al cadete de una modalidad a la otra.
 *
 * ⚠️ Se puede siempre, con plata en el medio o sin ella, como en el sistema viejo. Lo que
 * queda del otro lado lo dice el `confirm` antes de hacerlo, y el mensaje de vuelta lo
 * repite: el porqué de avisar en lugar de prohibir está en
 * `app/Services/CambioDeModalidadDeCobranza.php`.
 */
function Cambio({ cadete }: { cadete: EnLista }) {
    // Lo único que puede negarlo es el rol —un comercio no cambia nada acá—, y en ese
    // caso la fila no ofrece el botón.
    if (!cadete.cambio.permitido) {
        return null;
    }

    return (
        <Form
            action={`/panel/cadetes/cobranzas/saldo/${cadete.id}/modalidad`}
            method="patch"
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(
                    `El móvil ${cadete.movil} pasa a cobranza ${cadete.pasaria_a.toLowerCase()}.` +
                        (cadete.aviso === null ? '' : `\n\n${cadete.aviso}`) +
                        '\n\n¿Seguir?',
                )
            }
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    size="sm"
                    variant="secondary"
                    disabled={processing}
                >
                    Pasar a {cadete.pasaria_a.toLowerCase()}
                </Button>
            )}
        </Form>
    );
}

/**
 * El porcentaje que define el saldo mínimo.
 *
 * ⚠️ Es la configuración que la pantalla de Configuraciones dejó afuera a propósito
 * —«va con la cobranza por saldo, que todavía no está»—. Se guarda por la misma ruta y con
 * el mismo validador que las otras ocho.
 */
function ElPorcentaje({
    porcentaje,
    saldoMinimo,
}: {
    porcentaje: { id: number; titulo: string; valor: string | null } | null;
    saldoMinimo: number | null;
}) {
    if (porcentaje === null) {
        return (
            <Card className="text-muted-foreground p-4 text-sm">
                Falta la configuración <code>COBRANZA_SALDO_PORCENTAJE</code>, así que no
                se sabe cuál es el saldo mínimo y no se puede cargar saldo. Es una
                instalación a medio configurar, no algo que se arregle desde acá.
            </Card>
        );
    }

    return (
        <Card className="space-y-3 p-4">
            <Form
                action={`/panel/configuraciones/${porcentaje.id}`}
                method="patch"
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1.5">
                            <Label htmlFor="valor" className="text-xs">
                                {porcentaje.titulo}
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="valor"
                                    name="valor"
                                    defaultValue={porcentaje.valor ?? ''}
                                    inputMode="decimal"
                                    className="h-9"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    Guardar
                                </Button>
                            </div>
                            <InputError message={errors.valor} />
                        </div>

                        {saldoMinimo !== null && (
                            <p className="text-muted-foreground text-xs">
                                Con este porcentaje, el saldo mínimo para seguir tomando
                                pedidos es <strong>${saldoMinimo}</strong>.
                            </p>
                        )}
                    </>
                )}
            </Form>
        </Card>
    );
}

CobranzaSaldoIndex.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Cobranzas · Saldo', href: '/panel/cadetes/cobranzas/saldo' },
    ],
};
