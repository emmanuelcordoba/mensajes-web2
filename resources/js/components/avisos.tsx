import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/*
| Lo que el servidor contesta después de escribir.
|
| ⚠️ Los controladores vienen dejando `->with('success', ...)` desde la primera pantalla
| del panel, y hasta acá no lo leía nadie: el mensaje se guardaba en la sesión, la sesión lo
| descartaba en el siguiente pedido y la pantalla no decía nada. El panel viejo mostraba
| estas dos alertas arriba de cada vista.
|
| ⚠️ Se escucha el evento de Inertia y no se mira una prop con `useEffect`: un efecto
| atado al texto no vuelve a dispararse si el mensaje es idéntico dos veces seguidas —dar
| de baja dos cadetes iguales, o guardar dos veces la misma pantalla—. El evento ocurre una
| vez por visita, que es exactamente lo que un mensaje de sesión significa.
*/
export default function Avisos() {
    useEffect(
        () =>
            router.on('success', (evento) => {
                const aviso = evento.detail.page.props.aviso;

                if (aviso.success !== null) {
                    toast.success(aviso.success);
                }

                if (aviso.danger !== null) {
                    toast.error(aviso.danger);
                }
            }),
        [],
    );

    return null;
}
