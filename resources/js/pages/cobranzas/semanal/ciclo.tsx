import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| Abrir un ciclo de cobranza semanal: el primero de un cadete, o el siguiente.
|
| Una sola pantalla para lo que en el panel viejo eran dos vistas casi idénticas —`new` y
| `reconocersemana`— que mandaban al mismo método. La diferencia era con qué valores venían
| llenas.
|
| ⚠️ Y esa diferencia es el defecto de PANEL-21: al reconocer una semana, los dos campos de
| pagos vienen con los ACUMULADOS, y la deuda sale de restarlos de la cuota. Así, el cadete
| que pagó completo abre su segunda semana con deuda 0, y así todas las siguientes. SE
| PORTA ASÍ A PROPÓSITO, por decisión; el cálculo está congelado por un test y la pantalla
| muestra la cuenta antes de guardar, que es lo único que acá se puede hacer al respecto.
*/

type Cadete = {
    id: number;
    movil: number;
    nombre: string;
    vehiculo: string;
    cuota_propia: boolean;
    deuda: string;
};

type Propuesta = {
    fecha_inicio: string;
    fecha_vencimiento: string;
    monto_semanal: string;
    monto_pagado_efectivo: string;
    monto_pagado_tickets: string;
};

export default function CobranzaSemanalCiclo({
    cadete,
    es_el_primero,
    propuesta,
}: {
    cadete: Cadete;
    es_el_primero: boolean;
    propuesta: Propuesta;
}) {
    const [cuota, setCuota] = useState(propuesta.monto_semanal);
    const [efectivo, setEfectivo] = useState(propuesta.monto_pagado_efectivo);
    const [tickets, setTickets] = useState(propuesta.monto_pagado_tickets);

    // La misma cuenta que hace el servidor, para que se vea antes de guardar.
    const deuda = (
        Number(cuota || 0) -
        Number(efectivo || 0) -
        Number(tickets || 0)
    ).toFixed(2);

    return (
        <>
            <Head
                title={
                    es_el_primero
                        ? `Abrir cobranza · móvil ${cadete.movil}`
                        : `Reconocer semana · móvil ${cadete.movil}`
                }
            />

            <div className="max-w-3xl space-y-6 p-4">
                <Heading
                    title={es_el_primero ? 'Abrir la cobranza' : 'Reconocer semana'}
                    description={`Móvil N° ${cadete.movil} · ${cadete.nombre}`}
                />

                <Form
                    action={`/panel/cadetes/cobranzas/semanal/${cadete.id}/ciclo`}
                    method="post"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className="space-y-4 p-6">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="fecha_inicio">
                                            Fecha de inicio
                                        </Label>
                                        <Input
                                            id="fecha_inicio"
                                            name="fecha_inicio"
                                            type="date"
                                            defaultValue={propuesta.fecha_inicio}
                                            required
                                        />
                                        <InputError message={errors.fecha_inicio} />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="fecha_vencimiento">
                                            Fecha de vencimiento
                                        </Label>
                                        <Input
                                            id="fecha_vencimiento"
                                            name="fecha_vencimiento"
                                            type="date"
                                            defaultValue={propuesta.fecha_vencimiento}
                                            required
                                        />
                                        {/* ⚠️ El panel viejo lo calculaba con JavaScript y
                                            lo dejaba readonly; después no validaba el
                                            rango, y por ahí entraron los vencimientos en
                                            2027, 2029 y 3030. Acá se puede editar y el
                                            servidor exige un rango razonable. */}
                                        <p className="text-muted-foreground text-xs">
                                            Una semana desde el inicio.
                                        </p>
                                        <InputError message={errors.fecha_vencimiento} />
                                    </div>
                                </div>
                            </Card>

                            <Card className="space-y-4 p-6">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="monto_semanal">
                                            Cuota semanal
                                        </Label>
                                        <Input
                                            id="monto_semanal"
                                            name="monto_semanal"
                                            value={cuota}
                                            onChange={(e) => setCuota(e.target.value)}
                                            inputMode="decimal"
                                            required
                                            className="tabular-nums"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {cadete.cuota_propia
                                                ? 'Tiene cuota propia.'
                                                : 'La general de su vehículo.'}
                                        </p>
                                        <InputError message={errors.monto_semanal} />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="monto_pagado_efectivo">
                                            Pagado en efectivo
                                        </Label>
                                        <Input
                                            id="monto_pagado_efectivo"
                                            name="monto_pagado_efectivo"
                                            value={efectivo}
                                            onChange={(e) => setEfectivo(e.target.value)}
                                            inputMode="decimal"
                                            required
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            message={errors.monto_pagado_efectivo}
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="monto_pagado_tickets">
                                            Pagado en tickets
                                        </Label>
                                        <Input
                                            id="monto_pagado_tickets"
                                            name="monto_pagado_tickets"
                                            value={tickets}
                                            onChange={(e) => setTickets(e.target.value)}
                                            inputMode="decimal"
                                            required
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            message={errors.monto_pagado_tickets}
                                        />
                                    </div>
                                </div>

                                <div className="flex items-baseline justify-between gap-4 border-t pt-4">
                                    <span className="text-muted-foreground text-sm">
                                        Deuda con la que queda
                                    </span>
                                    <span className="text-2xl font-semibold tabular-nums">
                                        ${deuda}
                                    </span>
                                </div>

                                {!es_el_primero && (
                                    /* ⚠️ Lo que dice este cartel es el defecto de
                                       PANEL-21, puesto donde se decide. */
                                    <p className="text-muted-foreground text-xs">
                                        Los pagos vienen con lo que el cadete ya pagó, y la
                                        deuda sale de restarlos de la cuota. Si pagó la
                                        semana anterior completa, la nueva arranca en $0:
                                        poné los pagos en 0 si querés que deba la semana.
                                    </p>
                                )}
                            </Card>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {es_el_primero ? 'Abrir ciclo' : 'Reconocer semana'}
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href="/panel/cadetes/cobranzas/semanal">
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

CobranzaSemanalCiclo.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Cobranzas · Semanal', href: '/panel/cadetes/cobranzas/semanal' },
    ],
};
