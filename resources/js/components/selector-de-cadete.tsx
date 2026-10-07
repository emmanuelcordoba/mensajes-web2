import { useEffect, useMemo, useState } from 'react';

import { Input } from '@/components/ui/input';

/*
| A qué cadete se le da el pedido.
|
| Lo usan las dos pantallas que asignan: el alta de Inicio y el modal de la lista de espera.
| Es un componente y no dos copias porque la regla de a quién se puede ofrecer es una sola.
|
| ⚠️ **La cola arriba, y el resto buscando contra el servidor.** El panel viejo hace algo
| distinto y se contradice solo: al cargar la pantalla el `<select>` tiene los 134 cadetes no
| bloqueados, y cinco segundos después `recargarCadetesActivos()` lo reemplaza por
| `Cadete::all()` —220, **incluidos los 86 que tienen el usuario bloqueado**—. O sea que el
| selector se ensancha al primer refresco y se le podía asignar un pedido a alguien
| bloqueado. Acá la cola va primero porque es de donde sale el 97% de los pedidos, y los
| demás se buscan: nunca viajan 220 cadetes a la pantalla, y los bloqueados no aparecen.
|
| ⚠️ **Un cadete elegido de la búsqueda se queda en la lista.** La pantalla se refresca sola
| cada cinco segundos y con ella la cola; sin recordarlo, el que se eligió a mano desaparecía
| del selector y el formulario se quedaba apuntando a una opción que ya no estaba.
*/

export type CadeteElegible = {
    id: number;
    movil: number;
    nombre: string;
    en_la_app: boolean;
    /** Lo dice el buscador; los de la cola, por estar ahí, lo están. */
    en_la_cola?: boolean;
};

/**
 * Cómo se lee cada opción: igual que el `<select>` viejo, con el APP/WEB al final.
 *
 * ⚠️ El APP/WEB va **sólo para los de la cola**. En el panel viejo todas las opciones lo
 * llevan, y para alguien que no está trabajando las dos cosas son mentira: «WEB» quiere
 * decir que está en el mostrador esperando un pedido. Al que está fuera de la cola lo dice
 * el grupo en el que aparece.
 */
function comoSeLee(cadete: CadeteElegible): string {
    const nombre = `${cadete.movil} — ${cadete.nombre}`;

    if (cadete.en_la_cola === false) {
        return nombre;
    }

    return `${nombre} ${cadete.en_la_app ? '(APP)' : '(WEB)'}`;
}

export function SelectorDeCadete({
    cola,
    elegido,
    alElegir,
    nombre = 'cadete_id',
    id,
}: {
    cola: CadeteElegible[];
    elegido: number | null;
    alElegir: (id: number | null) => void;
    nombre?: string;
    id?: string;
}) {
    const [buscado, setBuscado] = useState('');
    const [encontrados, setEncontrados] = useState<CadeteElegible[]>([]);
    const [buscando, setBuscando] = useState(false);

    // El que se eligió fuera de la cola, recordado para que el refresco no se lo lleve.
    const [recordado, setRecordado] = useState<CadeteElegible | null>(null);

    useEffect(() => {
        if (buscado.trim() === '') {
            setEncontrados([]);
            setBuscando(false);

            return;
        }

        setBuscando(true);

        // Un respiro antes de preguntar: si no, se dispara una consulta por tecla.
        const espera = setTimeout(() => {
            void fetch(`/panel/cadetes/buscar?q=${encodeURIComponent(buscado)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : []))
                .then((datos: CadeteElegible[]) => setEncontrados(datos))
                .catch(() => setEncontrados([]))
                .finally(() => setBuscando(false));
        }, 300);

        return () => clearTimeout(espera);
    }, [buscado]);

    const enLaCola = useMemo(() => new Set(cola.map((c) => c.id)), [cola]);

    // Los de afuera que hay que ofrecer: lo encontrado, más el elegido si ya no aparece.
    const afuera = useMemo(() => {
        const lista = encontrados.filter((c) => !enLaCola.has(c.id));

        if (
            recordado !== null &&
            !enLaCola.has(recordado.id) &&
            !lista.some((c) => c.id === recordado.id)
        ) {
            return [recordado, ...lista];
        }

        return lista;
    }, [encontrados, enLaCola, recordado]);

    const cambiar = (valor: string) => {
        if (valor === '') {
            setRecordado(null);
            alElegir(null);

            return;
        }

        const elegidoId = Number(valor);
        const deAfuera = afuera.find((c) => c.id === elegidoId) ?? null;

        setRecordado(enLaCola.has(elegidoId) ? null : deAfuera);
        alElegir(elegidoId);
    };

    return (
        <div className="grid gap-1">
            <select
                id={id}
                name={nombre}
                value={elegido === null ? '' : String(elegido)}
                onChange={(e) => cambiar(e.target.value)}
                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Ninguno</option>

                {cola.length > 0 && (
                    <optgroup label="En la cola">
                        {cola.map((cadete) => (
                            <option key={cadete.id} value={cadete.id}>
                                {comoSeLee(cadete)}
                            </option>
                        ))}
                    </optgroup>
                )}

                {afuera.length > 0 && (
                    <optgroup label="Fuera de la cola">
                        {afuera.map((cadete) => (
                            <option key={cadete.id} value={cadete.id}>
                                {comoSeLee(cadete)}
                            </option>
                        ))}
                    </optgroup>
                )}
            </select>

            <Input
                value={buscado}
                onChange={(e) => setBuscado(e.target.value)}
                placeholder="Buscar otro cadete por móvil o nombre"
                autoComplete="off"
                className="h-8 text-xs"
                aria-label="Buscar un cadete que no está en la cola"
            />

            {/* Que no haya resultados es información: si no, el campo parece no funcionar. */}
            {buscado.trim() !== '' && !buscando && afuera.length === 0 && (
                <span className="text-muted-foreground text-xs">
                    Ningún cadete coincide. Los bloqueados no se pueden elegir.
                </span>
            )}

            {cola.length === 0 && (
                <span className="text-muted-foreground text-xs">
                    No hay nadie en la cola.
                </span>
            )}
        </div>
    );
}
