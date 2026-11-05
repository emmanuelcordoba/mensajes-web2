import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';

/*
| Parar el refresco de la pantalla mientras alguien tiene una lista desplegada.
|
| Es el arreglo que el panel viejo también tiene. Allá los selectores de cadete se rearman
| enteros cada cinco segundos —`select.innerHTML = options`—, y en el navegador del celular
| elegir con la lista abierta no siempre sale: se cierra sola, o queda apuntando a otro.
| Acá no se pisa el `innerHTML` —React reconcilia—, pero el problema de fondo es el mismo:
| si mientras la lista está abierta entra o sale alguien de la cola, las opciones que el
| navegador está mostrando cambian abajo. En el celular la lista es una pantalla aparte del
| sistema operativo, no un menú, y ahí se nota mucho más que en la computadora.
|
| Así que mientras una lista esté abierta la pantalla no se refresca, y al cerrarla vuelve.
|
| ⚠️ **La cuenta y no un booleano.** La portada tiene dos selectores —el del alta y el del
| modal de la lista de espera— y un `pausar()` del segundo no tiene por qué deshacer el
| `reanudar()` del primero. Se refresca de nuevo cuando no queda ninguno abierto.
|
| ⚠️ **El tope hace falta.** Una lista se puede cerrar sin que la página se entere: en el
| celular el `<select>` desplegado es del sistema operativo, y el toque que lo cierra sin
| elegir nada puede no llegar nunca acá. Sin tope, una pantalla que en la oficina está
| abierta todo el día se quedaría quieta para siempre. Pasado el tope se refresca igual,
| que es mucho mejor que mentir.
*/

/** Treinta segundos: seis vueltas del refresco de la portada. */
const TOPE_DE_LA_PAUSA = 30_000;

export type PausaDeRefresco = {
    /** Hay una lista abierta: que la pantalla no se refresque. */
    pausar: () => void;
    /** Esa lista se cerró. */
    reanudar: () => void;
};

/*
| ⚠️ Sin proveedor las dos cosas no hacen nada, y es a propósito: el selector de cadetes se
| puede dibujar en una pantalla que no se refresque, y no tiene por qué saber en cuál está.
*/
const Contexto = createContext<PausaDeRefresco>({
    pausar: () => {},
    reanudar: () => {},
});

export function ProveedorDePausaDeRefresco({
    parar,
    seguir,
    children,
}: {
    /** El `stop` del `usePoll` de la pantalla. */
    parar: () => void;
    /** Su `start`. */
    seguir: () => void;
    children: ReactNode;
}) {
    /*
    | ⚠️ Las dos van por una referencia, y hace falta: `pausar` y `reanudar` tienen que ser
    | siempre las mismas funciones —de ellas depende un efecto que al limpiarse reanuda—, y
    | `stop`/`start` del `usePoll` no prometen serlo.
    */
    const ultimas = useRef({ parar, seguir });

    useEffect(() => {
        ultimas.current = { parar, seguir };
    }, [parar, seguir]);

    /** Cuántas listas hay abiertas ahora mismo. */
    const abiertas = useRef(0);
    const tope = useRef<ReturnType<typeof setTimeout> | null>(null);

    const olvidarElTope = useCallback(() => {
        if (tope.current !== null) {
            clearTimeout(tope.current);
            tope.current = null;
        }
    }, []);

    const pausar = useCallback(() => {
        abiertas.current += 1;

        if (abiertas.current > 1) {
            return;
        }

        ultimas.current.parar();

        tope.current = setTimeout(() => {
            tope.current = null;
            ultimas.current.seguir();
        }, TOPE_DE_LA_PAUSA);
    }, []);

    const reanudar = useCallback(() => {
        if (abiertas.current === 0) {
            return;
        }

        abiertas.current -= 1;

        if (abiertas.current > 0) {
            return;
        }

        olvidarElTope();
        ultimas.current.seguir();
    }, [olvidarElTope]);

    /* Si alguien se va de la pantalla con una lista abierta, que no quede el tope colgado. */
    useEffect(() => olvidarElTope, [olvidarElTope]);

    const valor = useMemo(() => ({ pausar, reanudar }), [pausar, reanudar]);

    return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

export function usePausaDeRefresco(): PausaDeRefresco {
    return useContext(Contexto);
}

/**
 * Lo que necesita un `<select>` para que la pantalla no se refresque mientras está abierto.
 *
 * ⚠️ Un `<select>` no avisa cuándo se abre, así que lo que se mira es el foco: en el
 * celular tocarlo lo enfoca y despliega la lista. Se cierra con el `change` —eligió— o con
 * el `blur` —se fue a otra cosa—.
 */
export function usePausaMientrasEstaAbierto<T extends HTMLElement>(): {
    ref: RefObject<T | null>;
    onFocus: () => void;
    onBlur: () => void;
    /** Para llamar junto con lo que haga el `onChange` del `<select>`. */
    seCerro: () => void;
} {
    const { pausar, reanudar } = usePausaDeRefresco();
    const elemento = useRef<T | null>(null);
    const [abierta, setAbierta] = useState(false);

    const abrir = useCallback(() => setAbierta(true), []);
    const cerrar = useCallback(() => setAbierta(false), []);

    useEffect(() => {
        if (!abierta) {
            return;
        }

        pausar();

        /*
        | ⚠️ **Un toque en cualquier otra parte también cierra la lista, y no sobra.** El
        | `blur` no siempre llega: el selector del modal de la lista de espera no se
        | desmonta al cerrarse el modal —Radix lo deja en el documento—, así que sin esto la
        | pantalla se quedaba quieta hasta el tope. Mientras la lista desplegada tapa todo,
        | la página no recibe toques; el primero que recibe es el de alguien que volvió.
        */
        const toqueAfuera = (evento: PointerEvent) => {
            if (
                evento.target instanceof Node &&
                elemento.current?.contains(evento.target) === true
            ) {
                return;
            }

            setAbierta(false);
        };

        document.addEventListener('pointerdown', toqueAfuera, true);

        /* Y al desmontarse también reanuda, que es el otro final posible. */
        return () => {
            document.removeEventListener('pointerdown', toqueAfuera, true);
            reanudar();
        };
    }, [abierta, pausar, reanudar]);

    return { ref: elemento, onFocus: abrir, onBlur: cerrar, seCerro: cerrar };
}
