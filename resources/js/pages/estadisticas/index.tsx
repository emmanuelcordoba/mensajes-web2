import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| Estadísticas · pedidos por día.
|
| La pantalla vieja es una tabla de fecha y cantidad. Acá es la misma tabla con una barra por
| fila y el mes resumido arriba: con treinta y un números en columna no se ve nada, y lo que
| se mira en esta pantalla es la forma del mes —qué día se despachó más, qué días no se
| trabajó—, no el número exacto de un día.
|
| Las barras se dibujan con un div y un ancho en porcentaje. No hay librería de gráficos en el
| proyecto y no hace falta una para esto; si alguna vez hacen falta gráficos de verdad, lo que
| corresponde es agregar una y no seguir estirando esto.
|
| ⚠️ Los días en cero se listan igual, con la barra vacía. Son los domingos y los feriados, y
| que se vean es la mitad de la información.
*/

const RUTA = '/panel/estadisticas';

type Dia = {
    fecha: string;
    dia: number;
    diaSemana: string;
    cantidad: number;
    esHoy: boolean;
};

type Resumen = {
    total: string;
    promedio: string;
    dias: number;
    sinPedidos: number;
    pico: number;
    mejor: { etiqueta: string; cantidad: number } | null;
};

type ElMes = {
    valor: string;
    nombre: string;
    tope: string;
    anterior: string;
    siguiente: string | null;
};

export default function EstadisticasIndex({
    mes,
    dias,
    resumen,
}: {
    mes: ElMes;
    dias: Dia[];
    resumen: Resumen;
}) {
    return (
        <>
            <Head title="Estadísticas" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Estadísticas
                        </h1>
                        <p className="text-muted-foreground text-sm first-letter:uppercase">
                            Pedidos finalizados en {mes.nombre}
                        </p>
                    </div>

                    <ElSelectorDeMes mes={mes} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Numero titulo="Finalizados en el mes" valor={resumen.total} />
                    <Numero
                        titulo="Promedio por día"
                        valor={resumen.promedio}
                        pie={`sobre ${resumen.dias} ${resumen.dias === 1 ? 'día' : 'días'}`}
                    />
                    <Numero
                        titulo="Mejor día"
                        valor={
                            resumen.mejor === null
                                ? '—'
                                : String(resumen.mejor.cantidad)
                        }
                        pie={resumen.mejor?.etiqueta}
                    />
                    <Numero
                        titulo="Días sin pedidos"
                        valor={String(resumen.sinPedidos)}
                        pie="sin un solo finalizado"
                    />
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Día</th>
                                    <th className="px-4 py-3 font-medium">
                                        Pedidos finalizados
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Cantidad
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {dias.map((dia) => (
                                    <LaFila
                                        key={dia.fecha}
                                        dia={dia}
                                        pico={resumen.pico}
                                    />
                                ))}

                                {dias.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="text-muted-foreground px-4 py-8 text-center"
                                        >
                                            Ese mes todavía no empezó.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>
        </>
    );
}

function ElSelectorDeMes({ mes }: { mes: ElMes }) {
    const ir = (valor: string) => router.get(RUTA, { mes: valor });

    return (
        <div className="flex items-center gap-2">
            <Button variant="outline" size="icon" asChild>
                <Link
                    href={`${RUTA}?mes=${mes.anterior}`}
                    aria-label="El mes anterior"
                >
                    <ChevronLeft className="size-4" />
                </Link>
            </Button>

            {/* Se navega al cambiar el mes, como en el sistema viejo: no hay nada más que
                elegir, así que un botón de «ver» sería un paso de más. */}
            <Input
                type="month"
                name="mes"
                aria-label="Mes"
                className="w-40"
                value={mes.valor}
                max={mes.tope}
                onChange={(evento) => {
                    if (evento.target.value !== '') {
                        ir(evento.target.value);
                    }
                }}
            />

            <Button
                variant="outline"
                size="icon"
                disabled={mes.siguiente === null}
                asChild={mes.siguiente !== null}
            >
                {mes.siguiente === null ? (
                    <ChevronRight className="size-4" />
                ) : (
                    <Link
                        href={`${RUTA}?mes=${mes.siguiente}`}
                        aria-label="El mes siguiente"
                    >
                        <ChevronRight className="size-4" />
                    </Link>
                )}
            </Button>
        </div>
    );
}

function LaFila({ dia, pico }: { dia: Dia; pico: number }) {
    // Con el mes en cero no hay proporción que calcular, y dividir por cero daría NaN.
    const ancho = pico === 0 ? 0 : Math.round((dia.cantidad / pico) * 100);

    return (
        <tr className={`border-t ${dia.esHoy ? 'bg-muted/40' : ''}`}>
            <td className="w-32 px-4 py-2 whitespace-nowrap">
                <span className="text-muted-foreground">{dia.diaSemana}</span>{' '}
                <span className="font-medium">{dia.dia}</span>
                {dia.esHoy && (
                    <span className="text-muted-foreground ml-2 text-xs"> hoy</span>
                )}
            </td>

            <td className="px-4 py-2">
                {/* La barra es la misma información que el número de al lado, dibujada: para
                    quien no la ve, el número está en la celda siguiente. */}
                <div className="bg-muted h-2 w-full rounded-full" aria-hidden="true">
                    <div
                        className="bg-primary h-2 rounded-full"
                        style={{ width: `${ancho}%` }}
                    />
                </div>
            </td>

            <td
                className={`w-24 px-4 py-2 text-right tabular-nums ${
                    dia.cantidad === 0 ? 'text-muted-foreground' : 'font-medium'
                }`}
            >
                {dia.cantidad}
            </td>
        </tr>
    );
}

function Numero({
    titulo,
    valor,
    pie,
}: {
    titulo: string;
    valor: string;
    pie?: string;
}) {
    return (
        <Card className="gap-1 p-4">
            <span className="text-muted-foreground text-sm">{titulo}</span>
            <span className="text-2xl font-semibold tabular-nums">{valor}</span>
            {pie !== undefined && (
                <span className="text-muted-foreground text-xs">{pie}</span>
            )}
        </Card>
    );
}

EstadisticasIndex.layout = {
    breadcrumbs: [{ title: 'Estadísticas', href: RUTA }],
};
