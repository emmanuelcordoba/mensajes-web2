import { Badge } from '@/components/ui/badge';

/*
 * Lo que el listado y la pantalla de un pedido muestran igual.
 *
 * Vive acá y no en una de las dos páginas porque un monto formateado de dos formas
 * distintas según la pantalla es un error que nadie reporta: se ve bien en las dos.
 *
 * Las fechas NO están acá: las formatea el servidor. Ver App\Support\Momento, que
 * explica por qué —formatearlas en el cliente rompía la hidratación por tres horas—.
 */

/** Los que ya no esperan a nadie se ven distinto de los que sí. */
const RESUELTOS = ['Cancelado', 'Rechazado', 'Finalizado'];

export function EstadoBadge({ estado }: { estado: string }) {
    return (
        <Badge variant={RESUELTOS.includes(estado) ? 'secondary' : 'default'}>
            {estado}
        </Badge>
    );
}

/**
 * Un monto.
 *
 * ⚠️ El valor llega como cadena a propósito y acá **se formatea, no se convierte**:
 * `NUMERIC(12,2)` no pasa por float en ningún punto del camino, así que no hay dónde
 * perder un centavo.
 */
export function Monto({ valor }: { valor: string | null }) {
    if (valor === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const [entera, decimal = '00'] = valor.split('.');
    const conMiles = entera.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return (
        <span className="tabular-nums">
            ${conMiles},{decimal}
        </span>
    );
}
