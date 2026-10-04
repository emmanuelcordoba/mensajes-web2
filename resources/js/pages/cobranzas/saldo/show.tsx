import { Form, Head, Link } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Los movimientos de saldo de un cadete.
|
| ⚠️ Cada fila es plata. Los montos llegan como texto y se muestran como texto: no pasan
| por Number en ningún punto.
|
| ⚠️ Dos columnas de esta tabla existen por un error cada una, y las dos se ven acá:
|
| - «Quién» está vacío en 12.827 de los 13.412 movimientos. En el sistema viejo `user_id`
|   no estaba en el `fillable` y la asignación masiva lo descartaba en silencio
|   (PANEL-13): quedan sin atribuir para siempre. Los 585 que sí lo tienen son todos
|   posteriores al 2026-09-11, que es cuando eso se arregló allá. Los que escribe este
|   sistema lo tienen siempre.
| - «Pedido» es nueva. Sin ella no había forma de impedir ni de ver que un pedido se
|   cobrara dos veces: COB-1 midió 438 cobros dobles por $233.243,64, reconstruidos por
|   cercanía en el tiempo. Ahora un índice único parcial deja un solo descuento por pedido.
*/

type Movimiento = {
    id: number;
    cuando: string | null;
    tipo: string;
    monto: string;
    suma: boolean;
    saldo_previo: string | null;
    quien: string | null;
    pedido: number | null;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export default function CobranzaSaldoShow({
    cadete,
    movimientos,
    saldo_minimo,
    cargar,
}: {
    cadete: {
        id: number;
        movil: number;
        nombre: string;
        modalidad: string;
        saldo: string;
    };
    movimientos: Paginado<Movimiento>;
    /** Null si falta alguna de las dos configuraciones con que se calcula. */
    saldo_minimo: number | null;
    cargar: { permitido: boolean; motivo: string | null };
}) {
    const alcanza = saldo_minimo === null || Number(cadete.saldo) >= saldo_minimo;

    return (
        <>
            <Head title={`Saldo · móvil ${cadete.movil}`} />

            <div className="max-w-5xl space-y-6 p-4">
                <Heading
                    title={`Móvil N° ${cadete.movil}`}
                    description={cadete.nombre}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card className="space-y-1 p-6">
                        <p className="text-muted-foreground text-xs">Saldo</p>
                        <p
                            className={`text-3xl font-semibold tabular-nums ${
                                alcanza ? '' : 'text-red-600 dark:text-red-400'
                            }`}
                        >
                            ${cadete.saldo}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {saldo_minimo === null
                                ? 'Falta configurar el porcentaje, así que no se sabe cuál es el mínimo.'
                                : alcanza
                                  ? `Por encima del mínimo de $${saldo_minimo}.`
                                  : `Por debajo del mínimo de $${saldo_minimo}: no puede tomar pedidos.`}
                        </p>
                    </Card>

                    <Card className="space-y-4 p-6">
                        <h2 className="text-sm font-medium">Cargar saldo</h2>

                        {cargar.permitido ? (
                            <Form
                                action={`/panel/cadetes/cobranzas/saldo/${cadete.id}/cargas`}
                                method="post"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="space-y-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="monto">Monto</Label>
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    id="monto"
                                                    name="monto"
                                                    type="number"
                                                    min={saldo_minimo ?? 1}
                                                    step={1}
                                                    inputMode="numeric"
                                                    required
                                                    className="tabular-nums"
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Cargar
                                                </Button>
                                            </div>
                                            <InputError message={errors.monto} />
                                        </div>
                                        <p className="text-muted-foreground text-xs">
                                            En pesos enteros. La carga mínima es de $
                                            {saldo_minimo ?? 1}.
                                        </p>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {cargar.motivo ?? 'No se le puede cargar saldo.'}
                            </p>
                        )}
                    </Card>
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="flex items-center justify-between gap-4 border-b px-4 py-3">
                        <h2 className="text-sm font-medium">Movimientos</h2>
                        <span className="text-muted-foreground text-sm">
                            {movimientos.total.toLocaleString('es-AR')}
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Cuándo</th>
                                    <th className="px-4 py-3 font-medium">Tipo</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Monto
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Saldo previo
                                    </th>
                                    <th className="px-4 py-3 font-medium">Pedido</th>
                                    <th className="px-4 py-3 font-medium">Quién</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movimientos.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Todavía no hay movimientos.
                                        </td>
                                    </tr>
                                )}

                                {movimientos.data.map((movimiento) => (
                                    <tr
                                        key={movimiento.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                                            {movimiento.cuando}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    movimiento.suma
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {movimiento.tipo}
                                            </Badge>
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right tabular-nums ${
                                                movimiento.suma
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            }`}
                                        >
                                            {/* El signo no está en `monto`: está en
                                                `monto_positivo`. Se dibuja acá. */}
                                            {movimiento.suma ? '+' : '−'}${movimiento.monto}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {movimiento.saldo_previo === null ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                `$${movimiento.saldo_previo}`
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {movimiento.pedido ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                                            {movimiento.quien ?? (
                                                <span title="Los movimientos migrados no guardaron quién los hizo.">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {movimientos.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {movimientos.links.map((link, i) =>
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

                <Button asChild variant="ghost">
                    <Link href="/panel/cadetes/cobranzas/saldo">Volver al listado</Link>
                </Button>
            </div>
        </>
    );
}

CobranzaSaldoShow.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Cobranzas · Saldo', href: '/panel/cadetes/cobranzas/saldo' },
    ],
};
