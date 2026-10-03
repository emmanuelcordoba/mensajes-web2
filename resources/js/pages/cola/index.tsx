import { Form, Head, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';

/*
| La cola, y el mostrador por donde se entra a ella.
|
| ⚠️ ESTA PANTALLA NO ES PARA LA OFICINA. Vive en un monitor aparte, del lado del
| mostrador, y la maneja el cadete que llega: por eso no tiene menú lateral, ni barra de
| arriba, ni nada en lo que se pueda hacer clic para salir de acá. El menú del panel la
| abre en otra pestaña. Es lo mismo que hacía el sistema viejo, que para esta vista usaba
| `template.app` y no `template.main`.
|
| ⚠️ SE MANEJA CON UN TECLADO NUMÉRICO, que no tiene Tab. Por eso Enter en el número de
| móvil baja a la contraseña y Enter en la contraseña envía: sin eso, con un numérico no
| se puede llegar al segundo campo. El sistema viejo lo resolvía al revés —Enter enviaba
| el formulario con la contraseña vacía, el servidor respondía con un error y su
| JavaScript ponía el foco en la contraseña—: un viaje de ida y vuelta por cada cadete.
|
| ⚠️ Lo que escribe el cadete NO abre una sesión: la del navegador sigue siendo la de la
| oficina. La contraseña sólo prueba que el que se pone en la cola es él.
*/

type EnLaCola = {
    posicion: number;
    movil: number;
    vehiculo: string;
    en_la_app: boolean;
};

export default function ColaIndex({
    cola,
    refresco,
}: {
    cola: EnLaCola[];
    refresco: number;
}) {
    // La cola cambia sola —los cadetes entran desde la app y la central los saca—, así
    // que la pantalla se vuelve a pedir sin que nadie la toque. Sólo esa prop.
    usePoll(refresco, { only: ['cola'] });

    const movil = useRef<HTMLInputElement>(null);
    const password = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Cola de cadetes" />

            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="flex items-center justify-between gap-4 border-b px-6 py-4">
                    <div className="flex items-center gap-3">
                        <AppLogoIcon className="size-7" />
                        <h1 className="text-lg font-semibold tracking-tight">
                            Cola de cadetes
                        </h1>
                    </div>
                    <Reloj />
                </header>

                <div className="flex flex-1 flex-col gap-6 p-6 lg:flex-row">
                    <main className="flex-1">
                        <div className="mb-4 flex items-baseline gap-3">
                            <h2 className="text-muted-foreground text-sm font-medium">
                                En la cola
                            </h2>
                            <span className="text-2xl font-semibold tabular-nums">
                                {cola.length}
                            </span>
                        </div>

                        {cola.length === 0 ? (
                            <p className="text-muted-foreground rounded-lg border border-dashed p-12 text-center">
                                No hay ningún cadete en la cola.
                            </p>
                        ) : (
                            <ol className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                                {cola.map((cadete) => (
                                    <li
                                        key={cadete.movil}
                                        className="bg-card overflow-hidden rounded-lg border"
                                    >
                                        <div className="bg-muted flex items-center justify-between px-3 py-1.5">
                                            <span className="text-muted-foreground text-sm font-medium tabular-nums">
                                                {cadete.posicion}°
                                            </span>
                                            <span className="text-xs">
                                                {cadete.en_la_app ? 'App' : 'Web'}
                                            </span>
                                        </div>
                                        <div className="px-3 py-4 text-center">
                                            <p className="text-4xl font-bold tabular-nums">
                                                {cadete.movil}
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {cadete.vehiculo}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </main>

                    <aside className="w-full lg:max-w-md">
                        <div className="bg-card space-y-5 rounded-lg border p-6">
                            <h2 className="text-lg font-semibold">Entrar a la cola</h2>

                            <Form
                                action="/panel/cadetes/cola"
                                method="post"
                                options={{ preserveScroll: true }}
                                // Después de entrar, la pantalla queda lista para el que
                                // sigue: es un mostrador, no un formulario que se llena
                                // una vez.
                                //
                                // ⚠️ También se limpia cuando la regla dice que no —un
                                // bloqueo, la cuota vencida—, porque para Inertia esa
                                // respuesta es una visita exitosa. Está bien que sea así:
                                // lo que escribió no estaba mal, y el que sigue ya puede
                                // empezar. Lo que NO se limpia es un móvil o una
                                // contraseña equivocados, que vuelven como 422 y dejan lo
                                // escrito para poder corregirlo.
                                resetOnSuccess
                                onSuccess={() => movil.current?.focus()}
                                onError={(errores) =>
                                    (errores.numero_movil === undefined
                                        ? password.current
                                        : movil.current
                                    )?.focus()
                                }
                                className="space-y-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <label
                                                htmlFor="numero_movil"
                                                className="block text-base font-medium"
                                            >
                                                Número de móvil
                                            </label>
                                            <input
                                                id="numero_movil"
                                                name="numero_movil"
                                                type="number"
                                                min={1}
                                                ref={movil}
                                                required
                                                autoFocus
                                                autoComplete="off"
                                                inputMode="numeric"
                                                onKeyDown={(e) => {
                                                    // Enter baja a la contraseña en vez
                                                    // de enviar: el teclado numérico no
                                                    // tiene Tab.
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        password.current?.focus();
                                                    }
                                                }}
                                                className="border-input bg-background focus-visible:ring-ring h-16 w-full rounded-md border px-4 text-3xl tabular-nums focus-visible:ring-2 focus-visible:outline-none"
                                            />
                                            <InputError
                                                message={errors.numero_movil}
                                                className="text-base"
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <label
                                                htmlFor="password"
                                                className="block text-base font-medium"
                                            >
                                                Contraseña
                                            </label>
                                            <input
                                                id="password"
                                                name="password"
                                                type="password"
                                                ref={password}
                                                required
                                                autoComplete="off"
                                                // ⚠️ `inputMode` y nada más: es una
                                                // pista para los teclados en pantalla, no
                                                // una validación. Las contraseñas de los
                                                // cadetes migrados son numéricas, pero
                                                // una regla `[0-9]*` acá dejaría afuera a
                                                // cualquiera que tenga otra cosa.
                                                inputMode="numeric"
                                                className="border-input bg-background focus-visible:ring-ring h-16 w-full rounded-md border px-4 text-3xl tracking-widest focus-visible:ring-2 focus-visible:outline-none"
                                            />
                                            <InputError
                                                message={errors.password}
                                                className="text-base"
                                            />
                                        </div>

                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="bg-primary text-primary-foreground hover:bg-primary/90 h-16 w-full rounded-md text-xl font-semibold disabled:opacity-50"
                                        >
                                            Entrar a la cola
                                        </button>

                                        <p className="text-muted-foreground text-center text-sm">
                                            Escribí tu móvil, Enter, tu contraseña y Enter.
                                        </p>
                                    </>
                                )}
                            </Form>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

/**
 * El reloj del mostrador.
 *
 * ⚠️ No se dibuja hasta después de montar, y es a propósito: la hora del servidor y la del
 * navegador no son la misma, y renderizar un reloj en el primer pasado es exactamente el
 * desajuste de hidratación que React avisa. Es lo que dice el docblock de
 * App\Support\Momento: lo que tiene que correr se recalcula en el cliente DESPUÉS de
 * montar.
 */
function Reloj() {
    const [hora, setHora] = useState<string | null>(null);

    useEffect(() => {
        const marcar = () => setHora(new Date().toLocaleTimeString('es-AR'));

        marcar();
        const id = setInterval(marcar, 1000);

        return () => clearInterval(id);
    }, []);

    return (
        <p className="text-2xl font-semibold tabular-nums">{hora ?? '--:--:--'}</p>
    );
}
