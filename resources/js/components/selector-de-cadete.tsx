import { useMemo, useState } from 'react';

import { Input } from '@/components/ui/input';
import { usePausaMientrasEstaAbierto } from '@/hooks/use-pausa-de-refresco';

/*
| A qué cadete se le da el pedido.
|
| Lo usan las dos pantallas que asignan: el alta de Inicio y el modal de la lista de espera.
| Es un componente y no dos copias porque la regla de a quién se puede ofrecer es una sola.
|
| ⚠️ **Es la cola, en orden de llegada, y nada más que la cola.** El pedido le toca al que
| esperó más, así que la lista viene ordenada del servidor —`InicioController::paraAsignar()`—
| y acá no se reordena ni se agrupa. El panel viejo ofrecía el padrón entero: al cargar la
| pantalla los 134 cadetes no bloqueados y, cinco segundos después, `Cadete::all()` —220,
| **incluidos los 86 que tienen el usuario bloqueado**—. Al cadete que avisa por teléfono o
| pasa por el mostrador se lo pone en la cola y recién entonces se le da el pedido.
|
| ⚠️ **El campo de al lado filtra la lista; no le pregunta al servidor.** Filtrar es mirar lo
| que la pantalla ya tiene, y el orden de lo que queda sigue siendo el de la cola.
*/

export type CadeteElegible = {
    id: number;
    movil: number;
    nombre: string;
    en_la_app: boolean;
};

/**
 * Cómo se lee cada opción: el lugar en la cola adelante, y el APP/WEB al final como el
 * `<select>` viejo.
 *
 * ⚠️ El número es la posición en la cola, no el móvil ni el id: es lo que hace visible el
 * orden en una lista donde todos están esperando. «WEB» quiere decir que está en el
 * mostrador; «APP», que está en la calle con el teléfono.
 */
function comoSeLee(cadete: CadeteElegible, lugar: number): string {
    return `${lugar}. ${cadete.movil} — ${cadete.nombre} ${cadete.en_la_app ? '(APP)' : '(WEB)'}`;
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
    | ⚠️ **Y mientras está desplegada se dibujan los cadetes de cuando se abrió.** Parar el
    | refresco no alcanza: una respuesta puede venir en camino desde antes de abrirla, y la
    | pantalla también se refresca por su cuenta cuando se asigna o se borra un pedido. Si
    | la lista cambia abajo, en el celular elegir no sale. Es el mismo chequeo que el panel
    | viejo hace antes de pisar las opciones.
    |
    | Al cerrarse vuelve la lista de verdad, que para entonces ya está al día.
    */
    const [foto, setFoto] = useState<CadeteElegible[] | null>(null);

    const alAbrirse = () => {
        setFoto(cadetes);
        abierta.onFocus();
    };

    const alCerrarse = () => {
        setFoto(null);
        abierta.onBlur();
    };

    /* La lista se cierra sola con un toque afuera, y entonces la foto ya no manda. */
    const losQueSeVen = abierta.estaAbierta && foto !== null ? foto : cadetes;

    /*
    | La cola ya filtrada, con el lugar que cada uno tiene en ella.
    |
    | ⚠️ **El lugar se calcula antes de filtrar**: es la posición en la cola, no el renglón
    | de la lista. Filtrando por «Qui» el que está sexto sigue diciendo 6.
    |
    | ⚠️ **El elegido se muestra aunque el filtro lo deje afuera.** Si no, al seguir tipeando
    | su opción desaparecía del `<select>`, que quedaba apuntando a un valor que no estaba en
    | la lista: se veía vacío y se mandaba igual.
    */
    const queVan = useMemo(() => {
        const texto = buscado.trim().toLocaleLowerCase();

        return losQueSeVen
            .map((cadete, indice) => ({ cadete, lugar: indice + 1 }))
            .filter(
                ({ cadete }) =>
                    texto === '' || cadete.id === elegido || coincide(cadete, texto),
            );
    }, [losQueSeVen, buscado, elegido]);

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
                onFocus={alAbrirse}
                onBlur={alCerrarse}
                onChange={(e) => {
                    alCerrarse();
                    alElegir(e.target.value === '' ? null : Number(e.target.value));
                }}
                className="border-input bg-background h-9 w-full min-w-0 rounded-md border px-3 text-sm"
            >
                <option value="">Ninguno</option>

                {queVan.map(({ cadete, lugar }) => (
                    <option key={cadete.id} value={cadete.id}>
                        {comoSeLee(cadete, lugar)}
                    </option>
                ))}
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
            {buscado.trim() !== '' && queVan.length === 0 && (
                <span
                    className={`text-muted-foreground text-xs ${enLinea ? 'sm:col-span-2' : ''}`}
                >
                    Ningún cadete de la cola coincide.
                </span>
            )}

            {losQueSeVen.length === 0 && (
                <span
                    className={`text-muted-foreground text-xs ${enLinea ? 'sm:col-span-2' : ''}`}
                >
                    No hay nadie en la cola.
                </span>
            )}
        </div>
    );
}
