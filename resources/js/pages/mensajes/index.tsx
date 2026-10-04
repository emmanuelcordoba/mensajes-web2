import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/*
| El chat entre la central y los cadetes.
|
| Es la pantalla de Chat del menú lateral viejo, que sí existe y sí funciona: lo que
| PANEL-19 nombraba era otro método, `chat()`, sin ruta ni vista, que se borró del sistema
| viejo. La conversación del panel es `index()`.
|
| ⚠️ Quién escribió cada mensaje lo dice el servidor comparando dos columnas —el autor del
| mensaje contra la cuenta del cadete—. La vista vieja lo resolvía con
| `$mensaje->cadete->user->id == $mensaje->user->id`, que además de traer tres relaciones
| por fila revienta si la cuenta del cadete está dada de baja.
*/

type Conversacion = {
    cadete_id: number;
    movil: number;
    nombre: string;
    mensaje: string | null;
    cuando: string | null;
    del_cadete: boolean;
    sin_leer: number;
};

type MensajeEnPantalla = {
    id: number;
    mensaje: string | null;
    cuando: string | null;
    del_cadete: boolean;
};

type Abierta = {
    cadete: { id: number; movil: number; nombre: string };
    mensajes: MensajeEnPantalla[];
    total: number;
    completa: boolean;
    anteriores: number;
};

export default function MensajesIndex({
    conversaciones,
    abierta,
    refresco,
}: {
    conversaciones: Conversacion[];
    abierta: Abierta | null;
    refresco: number;
}) {
    // El chat cambia solo —los cadetes escriben desde la app—, así que la pantalla se
    // vuelve a pedir sin que nadie la toque, como el setInterval del panel viejo.
    usePoll(refresco, { only: ['conversaciones', 'abierta'] });

    return (
        <>
            <Head title="Chat" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">Chat</h1>
                    <NuevaConversacion />
                </div>

                <div className="grid flex-1 gap-4 lg:grid-cols-[22rem_1fr]">
                    <Card className="overflow-hidden p-0">
                        <div className="text-muted-foreground border-b px-4 py-3 text-sm font-medium">
                            Conversaciones
                        </div>

                        <ul className="max-h-[36rem] divide-y overflow-y-auto">
                            {conversaciones.length === 0 && (
                                <li className="text-muted-foreground px-4 py-8 text-center text-sm">
                                    Todavía no hay conversaciones.
                                </li>
                            )}

                            {conversaciones.map((una) => (
                                <li key={una.cadete_id}>
                                    <Link
                                        href={`/panel/mensajes/${una.cadete_id}`}
                                        preserveScroll
                                        className={`hover:bg-muted block px-4 py-3 ${
                                            abierta?.cadete.id === una.cadete_id
                                                ? 'bg-muted'
                                                : ''
                                        }`}
                                    >
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="truncate text-sm font-medium">
                                                Móvil {una.movil} · {una.nombre}
                                            </span>
                                            {una.sin_leer > 0 && (
                                                <Badge variant="destructive">
                                                    {una.sin_leer}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground mt-0.5 truncate text-sm">
                                            {una.del_cadete ? '' : 'Vos: '}
                                            {una.mensaje ?? <em>sin texto</em>}
                                        </p>
                                        <p className="text-muted-foreground mt-0.5 text-xs">
                                            {una.cuando}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    {abierta === null ? (
                        <Card className="text-muted-foreground flex items-center justify-center p-12 text-sm">
                            Elegí una conversación, o empezá una nueva.
                        </Card>
                    ) : (
                        <Conversacion abierta={abierta} />
                    )}
                </div>
            </div>
        </>
    );
}

/** La conversación abierta: los mensajes y el campo para escribir. */
function Conversacion({ abierta }: { abierta: Abierta }) {
    const fondo = useRef<HTMLDivElement>(null);
    const ultimo = abierta.mensajes.at(-1)?.id;

    // Al abrir la conversación y al llegar un mensaje nuevo, se va al final: es lo que
    // hacía `scrollToBottom()` en el panel viejo.
    useEffect(() => {
        fondo.current?.scrollIntoView();
    }, [abierta.cadete.id, ultimo]);

    return (
        <Card className="flex flex-col overflow-hidden p-0">
            <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <span className="text-sm font-medium">
                    Móvil N° {abierta.cadete.movil} · {abierta.cadete.nombre}
                </span>
                <span className="text-muted-foreground text-xs">
                    {abierta.total.toLocaleString('es-AR')} mensajes
                </span>
            </div>

            <div className="max-h-[32rem] flex-1 space-y-3 overflow-y-auto p-4">
                {abierta.anteriores > 0 && (
                    /* ⚠️ El panel viejo traía la conversación entera en cada carga y en
                       cada refresco, cada cinco segundos. Acá vienen los últimos 100 y el
                       historial se pide. */
                    <div className="text-center">
                        <Link
                            href={`/panel/mensajes/${abierta.cadete.id}?completa=1`}
                            preserveScroll
                            className="text-muted-foreground text-xs underline underline-offset-2"
                        >
                            Ver los {abierta.anteriores} mensajes anteriores
                        </Link>
                    </div>
                )}

                {abierta.mensajes.length === 0 && (
                    <p className="text-muted-foreground py-8 text-center text-sm">
                        Todavía no se escribieron mensajes.
                    </p>
                )}

                {abierta.mensajes.map((mensaje) => (
                    <div
                        key={mensaje.id}
                        className={`flex ${mensaje.del_cadete ? 'justify-start' : 'justify-end'}`}
                    >
                        <div
                            className={`max-w-[75%] rounded-lg px-3 py-2 ${
                                mensaje.del_cadete
                                    ? 'bg-muted'
                                    : 'bg-primary text-primary-foreground'
                            }`}
                        >
                            <p className="text-sm break-words">
                                {mensaje.mensaje ?? (
                                    <em className="opacity-70">sin texto</em>
                                )}
                            </p>
                            <p className="mt-1 text-[0.7rem] opacity-70">
                                {mensaje.cuando}
                            </p>
                        </div>
                    </div>
                ))}

                <div ref={fondo} />
            </div>

            <div className="border-t p-4">
                <Form
                    action={`/panel/mensajes/${abierta.cadete.id}`}
                    method="post"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex items-center gap-2">
                                <Input
                                    name="mensaje"
                                    placeholder="Escribí un mensaje"
                                    maxLength={255}
                                    required
                                    autoComplete="off"
                                    autoFocus
                                />
                                <Button type="submit" disabled={processing}>
                                    Enviar
                                </Button>
                            </div>
                            <InputError message={errors.mensaje} />
                        </>
                    )}
                </Form>
            </div>
        </Card>
    );
}

/**
 * Empezar una conversación con un cadete que todavía no tiene ninguna.
 *
 * ⚠️ Se busca contra el servidor. El `<select>` del panel viejo listaba los 1.768 cadetes
 * en cada carga de la pantalla, y la pantalla se recarga sola cada cinco segundos.
 */
function NuevaConversacion() {
    const [buscado, setBuscado] = useState('');
    const [encontrados, setEncontrados] = useState<{ id: number; nombre: string }[]>([]);

    useEffect(() => {
        if (buscado.trim() === '') {
            setEncontrados([]);

            return;
        }

        // Un respiro antes de preguntar: si no, se dispara una consulta por tecla.
        const espera = setTimeout(() => {
            void fetch(
                `/panel/mensajes/cadetes?q=${encodeURIComponent(buscado)}`,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => (r.ok ? r.json() : []))
                .then((datos: { id: number; nombre: string }[]) =>
                    setEncontrados(datos),
                )
                .catch(() => setEncontrados([]));
        }, 300);

        return () => clearTimeout(espera);
    }, [buscado]);

    return (
        <div className="relative w-72">
            <Input
                value={buscado}
                onChange={(e) => setBuscado(e.target.value)}
                placeholder="Escribirle a un cadete"
                autoComplete="off"
            />

            {encontrados.length > 0 && (
                <ul className="bg-popover absolute right-0 left-0 z-10 mt-1 max-h-72 divide-y overflow-y-auto rounded-md border shadow-md">
                    {encontrados.map((cadete) => (
                        <li key={cadete.id}>
                            <button
                                type="button"
                                className="hover:bg-muted w-full px-3 py-2 text-left text-sm"
                                onClick={() => {
                                    setBuscado('');
                                    setEncontrados([]);
                                    router.get(`/panel/mensajes/${cadete.id}`);
                                }}
                            >
                                {cadete.nombre}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

MensajesIndex.layout = {
    breadcrumbs: [{ title: 'Chat', href: '/panel/mensajes' }],
};
