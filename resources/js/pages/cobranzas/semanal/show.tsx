import { Form, Head, Link } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| La cobranza semanal de un cadete: el ciclo en curso y el pago que se registra contra él.
|
| ⚠️ De los ocho campos que mandaba el formulario viejo, SEIS eran de sólo lectura: estaban
| para que el empleado viera el estado de la cuenta mientras cargaba el pago. Acá esos seis
| son texto, que es lo que siempre fueron: `readonly` es del navegador, y mandarlos invitaba
| a que el servidor los usara —que es exactamente PANEL-9—.
*/

type Cadete = {
    id: number;
    movil: number;
    nombre: string;
    vehiculo: string;
    cuota_propia: boolean;
    fecha_inicio: string | null;
    fecha_vencimiento: string | null;
    vencimiento: string | null;
    vencido: boolean;
    cuota: string;
    efectivo: string;
    tickets: string;
    deuda: string;
};

export default function CobranzaSemanalShow({ cadete }: { cadete: Cadete }) {
    return (
        <>
            <Head title={`Cobranza · móvil ${cadete.movil}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading
                    title={`Móvil N° ${cadete.movil}`}
                    description={`${cadete.nombre} · ${cadete.vehiculo}`}
                />

                <Card className="space-y-4 p-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-medium">El ciclo en curso</h2>
                        {cadete.vencido && (
                            <Badge variant="destructive">Vencido</Badge>
                        )}
                    </div>

                    <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-3">
                        <Dato nombre="Inicio" valor={cadete.fecha_inicio} />
                        <Dato nombre="Vencimiento" valor={cadete.vencimiento} />
                        <Dato
                            nombre="Cuota semanal"
                            valor={`$${cadete.cuota}`}
                            nota={cadete.cuota_propia ? 'propia' : 'la general de su vehículo'}
                        />
                        <Dato nombre="Pagado en efectivo" valor={`$${cadete.efectivo}`} />
                        <Dato nombre="Pagado en tickets" valor={`$${cadete.tickets}`} />
                        <Dato nombre="Deuda" valor={`$${cadete.deuda}`} destacado />
                    </dl>
                </Card>

                <Card className="space-y-4 p-6">
                    <h2 className="text-sm font-medium">Registrar un pago</h2>

                    <Form
                        action={`/panel/cadetes/cobranzas/semanal/${cadete.id}/pagos`}
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess={['monto_efectivo', 'monto_tickets']}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="monto_efectivo">Efectivo</Label>
                                        <Input
                                            id="monto_efectivo"
                                            name="monto_efectivo"
                                            defaultValue="0"
                                            inputMode="decimal"
                                            required
                                            className="tabular-nums"
                                        />
                                        <InputError message={errors.monto_efectivo} />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="monto_tickets">Tickets</Label>
                                        <Input
                                            id="monto_tickets"
                                            name="monto_tickets"
                                            defaultValue="0"
                                            inputMode="decimal"
                                            required
                                            className="tabular-nums"
                                        />
                                        <InputError message={errors.monto_tickets} />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="fecha_vencimiento">
                                            Vencimiento
                                        </Label>
                                        <Input
                                            id="fecha_vencimiento"
                                            name="fecha_vencimiento"
                                            type="date"
                                            defaultValue={cadete.fecha_vencimiento ?? ''}
                                            required
                                        />
                                        <InputError message={errors.fecha_vencimiento} />
                                    </div>
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    {/* La cuota NO viaja: la deuda se recalcula con la
                                        guardada. Ver PANEL-9. */}
                                    Lo que se cargue se suma a lo ya pagado, y la deuda se
                                    recalcula contra la cuota guardada.
                                </p>

                                <Button type="submit" disabled={processing}>
                                    Registrar
                                </Button>
                            </>
                        )}
                    </Form>
                </Card>

                <Card className="flex flex-wrap items-center gap-3 p-6">
                    <Form
                        action={`/panel/cadetes/cobranzas/semanal/${cadete.id}/vencimiento`}
                        method="patch"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={processing}
                            >
                                Extender un día
                            </Button>
                        )}
                    </Form>

                    <Button asChild variant="secondary">
                        <Link
                            href={`/panel/cadetes/cobranzas/semanal/${cadete.id}/ciclo`}
                        >
                            Reconocer semana
                        </Link>
                    </Button>

                    <p className="text-muted-foreground text-xs">
                        Extender también lo desbloquea, como en el panel viejo.
                    </p>
                </Card>

                <Button asChild variant="ghost">
                    <Link href="/panel/cadetes/cobranzas/semanal">Volver al listado</Link>
                </Button>
            </div>
        </>
    );
}

function Dato({
    nombre,
    valor,
    nota,
    destacado = false,
}: {
    nombre: string;
    valor: string | null;
    nota?: string;
    destacado?: boolean;
}) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{nombre}</dt>
            <dd
                className={`tabular-nums ${destacado ? 'text-xl font-semibold' : 'text-sm'}`}
            >
                {valor ?? <span className="text-muted-foreground">—</span>}
            </dd>
            {nota !== undefined && (
                <p className="text-muted-foreground text-xs">{nota}</p>
            )}
        </div>
    );
}

CobranzaSemanalShow.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Cobranzas · Semanal', href: '/panel/cadetes/cobranzas/semanal' },
    ],
};
