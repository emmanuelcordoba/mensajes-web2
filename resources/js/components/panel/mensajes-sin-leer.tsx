import { Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useEffect, useState } from 'react';

/*
| El sobre de la barra de arriba, con el globito rojo de lo que falta leer.
|
| Es el `<li>` del sobre de la barra vieja: el ícono lleva al Chat y el contador es un
| `badge badge-danger badge-counter` que un `setInterval` de diez segundos vuelve a pedir.
| Acá el número llega dos veces —en las props compartidas, para que esté desde la primera
| pintura, y después de cada refresco— pero sale del mismo lugar: ver
| `Mensaje::cuantosEsperanALaCentral()`.
|
| ⚠️ **El refresco es un `fetch` y no una recarga parcial de Inertia.** Esto vive en todas
| las pantallas; pedirlo por Inertia obligaría a volver a armar las props de la que esté
| abierta cada diez segundos, que es muchísimo más caro que contar una fila. Es lo mismo
| que hace el panel viejo con `ajax/mensajes/noleidos`.
|
| ⚠️ **En cero no hay globito**, como allá. Y a diferencia de allá, cuando el número baja a
| cero el globito se va: el JavaScript viejo hace `if(result.cantidad)`, así que con cero
| —que es falso— no entra a la rama y el número de antes se queda dibujado hasta que
| alguien recarga la página.
|
| ⚠️ No se dibuja para quien no puede mirar el chat: el servidor manda null y acá no hay
| nada que esconder con CSS. Ver HandleInertiaRequests::mensajesSinLeer().
*/

/** Cada cuánto se vuelve a preguntar, en milisegundos. Los diez segundos del viejo. */
const REFRESCO = 10_000;

export function MensajesSinLeer() {
    // ⚠️ El tipo va acá y no se lee del de las props compartidas: la ampliación de
    // `@inertiajs/core` que lo declara no la toma `usePage()` sin genérico, y hoy eso deja
    // 24 errores de tipos repartidos por los componentes que lo hacen. Mientras eso no se
    // arregle, uno nuevo no suma otro.
    const { mensajesSinLeer } = usePage<{ mensajesSinLeer: number | null }>().props;
    const [cuantos, setCuantos] = useState(mensajesSinLeer ?? 0);

    // El número de las props manda cuando cambia: una visita nueva ya lo trae al día, y
    // así abrir una conversación baja el globito sin esperar al próximo refresco.
    useEffect(() => {
        if (typeof mensajesSinLeer === 'number') {
            setCuantos(mensajesSinLeer);
        }
    }, [mensajesSinLeer]);

    useEffect(() => {
        if (mensajesSinLeer === null || mensajesSinLeer === undefined) {
            return;
        }

        let vivo = true;

        const preguntar = async () => {
            try {
                const respuesta = await fetch('/panel/mensajes/sin-leer', {
                    headers: { Accept: 'application/json' },
                });

                if (!respuesta.ok) {
                    // ⚠️ Una sesión vencida contesta 401/419 acá antes que en ningún otro
                    // lado, porque esto pregunta solo. Recargar lleva al login, que es lo
                    // que hace el panel viejo con su `if(error.status == 401)`.
                    if (respuesta.status === 401 || respuesta.status === 419) {
                        window.location.reload();
                    }

                    return;
                }

                const datos = (await respuesta.json()) as { cantidad: number };

                if (vivo) {
                    setCuantos(datos.cantidad);
                }
            } catch {
                // Sin internet por un rato no es motivo de nada: el próximo vuelve a
                // probar y el número se queda como estaba.
            }
        };

        const reloj = setInterval(preguntar, REFRESCO);

        return () => {
            vivo = false;
            clearInterval(reloj);
        };
    }, [mensajesSinLeer]);

    if (mensajesSinLeer === null || mensajesSinLeer === undefined) {
        return null;
    }

    return (
        <Link
            href="/panel/mensajes"
            className="hover:bg-accent relative flex size-9 items-center justify-center rounded-md"
            aria-label={
                cuantos === 0
                    ? 'Chat con los cadetes'
                    : `Chat con los cadetes, ${cuantos} sin leer`
            }
            title="Chat"
        >
            <Mail className="size-5" aria-hidden />

            {cuantos > 0 && (
                <span
                    className="bg-destructive absolute top-1 right-0.5 min-w-4 rounded-full px-1 text-center text-[10px] leading-4 font-semibold text-white"
                    aria-hidden
                >
                    {/* Hasta 99, como cualquier globito: con tres cifras deja de entrar y
                        deja de importar el número exacto. */}
                    {cuantos > 99 ? '99+' : cuantos}
                </span>
            )}
        </Link>
    );
}
