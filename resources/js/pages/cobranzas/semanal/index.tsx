import { Form, Head, Link, router } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| Cobranzas · Semanal.
|
| ⚠️ ESTA MODALIDAD ESTÁ MUERTA Y SE PORTÓ IGUAL. 521 cadetes abrieron un ciclo alguna vez,
| 10 registraron un pago, y el último fue el 2022-04-09. La empresa cobra por saldo. Se
| porta porque la pantalla está en el menú viejo y sacarla no es decisión de quien porta.
|
| ⚠️ Y la aritmética de «reconocer semana» está mal, documentada en PANEL-21 y congelada por
| un test. Se deja como está, por decisión.
*/

type EnLista = {
    id: number;
    movil: number;
    nombre: string;
    vehiculo: string;
    tiene_ciclo: boolean;
    vencimiento: string | null;
    vencido: boolean;
    cuota: string;
    deuda: string;
};

type Paginado<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

type MontoGeneral = { tipo_vehiculo: string; vehiculo: string; monto: string };

export default function CobranzaSemanalIndex({
    cadetes,
    filtros,
    totales,
    montos,
}: {
    cadetes: Paginado<EnLista>;
    filtros: { buscar: string };
    totales: { deuda: string; efectivo: string; tickets: string };
    montos: MontoGeneral[];
}) {
    return (
        <>
            <Head title="Cobranza semanal" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Cobranzas · Semanal
                    </h1>
                    <span className="text-muted-foreground text-sm">
                        {cadetes.from ?? 0}–{cadetes.to ?? 0} de {cadetes.total}
                    </span>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Total titulo="Deuda" valor={totales.deuda} />
                    <Total titulo="Pagado en efectivo" valor={totales.efectivo} />
                    <Total titulo="Pagado en tickets" valor={totales.tickets} />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="p-4 lg:col-span-2">
                        <form
                            method="get"
                            action="/panel/cadetes/cobranzas/semanal"
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                const datos = new FormData(e.currentTarget);
                                router.get('/panel/cadetes/cobranzas/semanal', {
                                    buscar: String(datos.get('buscar') ?? ''),
                                });
                            }}
                        >
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

                            {filtros.buscar !== '' && (
                                <Button asChild variant="ghost">
                                    <Link href="/panel/cadetes/cobranzas/semanal">
                                        Limpiar
                                    </Link>
                                </Button>
                            )}
                        </form>
                    </Card>

                    <LasCuotas montos={montos} />
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Móvil</th>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 font-medium">Vehículo</th>
                                    <th className="px-4 py-3 font-medium">Vencimiento</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Deuda
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
                                            colSpan={6}
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
                                        <td className="px-4 py-3">{cadete.vehiculo}</td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {cadete.tiene_ciclo ? (
                                                <span
                                                    className={
                                                        cadete.vencido
                                                            ? 'text-red-600 dark:text-red-400'
                                                            : ''
                                                    }
                                                >
                                                    {cadete.vencimiento}
                                                </span>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Sin ciclo
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            ${cadete.deuda}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <Button asChild size="sm" variant="outline">
                                                <Link
                                                    href={`/panel/cadetes/cobranzas/semanal/${cadete.id}`}
                                                >
                                                    {cadete.tiene_ciclo
                                                        ? 'Cobranza'
                                                        : 'Abrir ciclo'}
                                                </Link>
                                            </Button>
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
 * Uno de los tres totales.
 *
 * ⚠️ Suman sobre TODOS los cadetes, como el listado viejo, que hacía `Cadete::sum()` sin
 * filtrar por modalidad. Incluye por lo tanto a los de saldo, cuyas columnas semanales
 * quedaron con lo que tuvieran. Se deja igual: cambiar un número que la oficina mira se
 * decide, no se arregla al pasar.
 */
function Total({ titulo, valor }: { titulo: string; valor: string }) {
    return (
        <Card className="space-y-1 p-4">
            <p className="text-muted-foreground text-xs">{titulo}</p>
            <p className="text-2xl font-semibold tabular-nums">${valor}</p>
        </Card>
    );
}

/** Las dos cuotas generales, una por vehículo. No se crean ni se borran: se editan. */
function LasCuotas({ montos }: { montos: MontoGeneral[] }) {
    return (
        <Card className="p-4">
            <Form
                action="/panel/cadetes/cobranzas/semanal/cuotas"
                method="patch"
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <p className="text-muted-foreground text-xs">
                            Cuota semanal general
                        </p>

                        {montos.map((monto, i) => (
                            <div
                                key={monto.tipo_vehiculo}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="hidden"
                                    name={`montos[${i}][tipo_vehiculo]`}
                                    value={monto.tipo_vehiculo}
                                />
                                <label
                                    htmlFor={`monto-${monto.tipo_vehiculo}`}
                                    className="w-24 text-sm"
                                >
                                    {monto.vehiculo}
                                </label>
                                <Input
                                    id={`monto-${monto.tipo_vehiculo}`}
                                    name={`montos[${i}][monto]`}
                                    defaultValue={monto.monto}
                                    inputMode="decimal"
                                    className="h-9 tabular-nums"
                                />
                            </div>
                        ))}

                        <InputError message={errors['montos.0.monto']} />
                        <InputError message={errors['montos.1.monto']} />

                        <Button
                            type="submit"
                            size="sm"
                            variant="secondary"
                            disabled={processing}
                        >
                            Guardar cuotas
                        </Button>

                        <p className="text-muted-foreground text-xs">
                            La usan los cadetes que no tienen una cuota propia.
                        </p>
                    </>
                )}
            </Form>
        </Card>
    );
}

CobranzaSemanalIndex.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Cobranzas · Semanal', href: '/panel/cadetes/cobranzas/semanal' },
    ],
};
