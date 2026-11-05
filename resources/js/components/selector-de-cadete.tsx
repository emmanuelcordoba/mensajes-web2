import { useMemo, useState } from 'react';

import { Input } from '@/components/ui/input';
import { usePausaMientrasEstaAbierto } from '@/hooks/use-pausa-de-refresco';

/*
| A qué cadete se le da el pedido.
|
| Lo usan las dos pantallas que asignan: el alta de Inicio y el modal de la lista de espera.
| Es un componente y no dos copias porque la regla de a quién se puede ofrecer es una sola.
|
| ⚠️ **La cola arriba y el resto abajo, y están todos en la lista.** El panel viejo hace algo
| distinto y se contradice solo: al cargar la pantalla el `<select>` tiene los 134 cadetes no
| bloqueados, y cinco segundos después `recargarCadetesActivos()` lo reemplaza por
| `Cadete::all()` —220, **incluidos los 86 que tienen el usuario bloqueado**—. Acá la cola va
| primero porque es de donde sale el 97% de los pedidos, y abajo está el resto del padrón
| esté el cadete activo o no, porque se le asigna a alguien fuera de la cola cuando avisa por
| teléfono o pasa por el mostrador. Los bloqueados no aparecen nunca.
|
| ⚠️ **El campo de al lado filtra la lista; ya no le pregunta al servidor.** Antes el resto
| de los cadetes no viajaba y había que buscarlos contra el servidor, una consulta por
| tecla. Ahora están todos acá: filtrar es mirar lo que la pantalla ya tiene.
*/

export type CadeteElegible = {
    id: number;
    movil: number;
    nombre: string;
    en_la_app: boolean;
    en_la_cola: boolean;
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

    if (!cadete.en_la_cola) {
        return nombre;
    }

    return `${nombre} ${cadete.en_la_app ? '(APP)' : '(WEB)'}`;
}

/** Si lo tipeado aparece en el móvil o en el nombre, sin distinguir mayúsculas. */
function coincide(cadete: CadeteElegible, buscado: string): boolean {
    return (
        String(cadete.movil).includes(buscado) ||
        cadete.nombre.toLocaleLowerCase().includes(buscado)
    );
}

export function SelectorDeCadete({
    cadetes,
    elegido,
    alElegir,
    nombre = 'cadete_id',
    id,
    enLinea = false,
}: {
    cadetes: CadeteElegible[];
    elegido: number | null;
    alElegir: (id: number | null) => void;
    nombre?: string;
    id?: string;
    /** El filtro al lado del selector y no debajo, donde la fila sobra ancho. */
    enLinea?: boolean;
}) {
    const [buscado, setBuscado] = useState('');

    /*
    | ⚠️ **Mientras la lista está desplegada, la pantalla no se refresca.** Si no, lo que el
    | navegador está mostrando cambia abajo —entra o sale alguien de la cola— y en el
    | celular elegir no sale: la lista se cierra sola o queda apuntando a otro cadete. Es
    | el mismo arreglo que tiene el panel viejo. Quién para el refresco y cuándo vuelve
    | está en el hook; acá sólo se avisa.
    */
    const abierta = usePausaMientrasEstaAbierto<HTMLSelectElement>();

    /*
    | Los dos grupos, ya filtrados.
    |
    | ⚠️ **El elegido se muestra aunque el filtro lo deje afuera.** Si no, al seguir tipeando
    | su opción desaparecía del `<select>`, que quedaba apuntando a un valor que no estaba en
    | la lista: se veía vacío y se mandaba igual.
    */
    const { enLaCola, afuera } = useMemo(() => {
        const texto = buscado.trim().toLocaleLowerCase();

        const queVan = cadetes.filter(
            (c) => texto === '' || c.id === elegido || coincide(c, texto),
        );

        return {
            enLaCola: queVan.filter((c) => c.en_la_cola),
            afuera: queVan.filter((c) => !c.en_la_cola),
        };
    }, [cadetes, buscado, elegido]);

    const hayCola = cadetes.some((c) => c.en_la_cola);

    /*
    | ⚠️ `min-w-0` en los tres, y no es cosmético: el ancho mínimo de un `<select>` es el de
    | su opción más larga, así que con un cadete de nombre largo en la cola se desbordaba de
    | su columna y se salía del modal —se veía—. El `min-width: auto` que traen los hijos de
    | una grilla es justo lo que hay que apagar.
    */
    return (
        <div
            className={`grid min-w-0 gap-1 ${enLinea ? 'sm:grid-cols-2 sm:items-start sm:gap-2' : ''}`}
        >
            <select
                ref={abierta.ref}
                id={id}
                name={nombre}
                value={elegido === null ? '' : String(elegido)}
                onFocus={abierta.onFocus}
                onBlur={abierta.onBlur}
                onChange={(e) => {
                    abierta.seCerro();
                    alElegir(e.target.value === '' ? null : Number(e.target.value));
                }}
                className="border-input bg-background h-9 w-full min-w-0 rounded-md border px-3 text-sm"
            >
                <option value="">Ninguno</option>

                {enLaCola.length > 0 && (
                    <optgroup label="En la cola">
                        {enLaCola.map((cadete) => (
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
                placeholder="Filtrar por móvil o nombre"
                autoComplete="off"
                className={`min-w-0 text-xs ${enLinea ? 'h-9' : 'h-8'}`}
                aria-label="Filtrar la lista de cadetes"
            />

            {/* Que no quede nadie es información: si no, el campo parece no funcionar. */}
            {buscado.trim() !== '' && enLaCola.length === 0 && afuera.length === 0 && (
                <span
                    className={`text-muted-foreground text-xs ${enLinea ? 'sm:col-span-2' : ''}`}
                >
                    Ningún cadete coincide. Los bloqueados no se pueden elegir.
                </span>
            )}

            {!hayCola && (
                <span
                    className={`text-muted-foreground text-xs ${enLinea ? 'sm:col-span-2' : ''}`}
                >
                    No hay nadie en la cola.
                </span>
            )}
        </div>
    );
}
