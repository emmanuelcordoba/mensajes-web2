import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';

import Heading from '@/components/heading';
import { Card } from '@/components/ui/card';

/*
| Una pantalla del panel que todavía no está hecha.
|
| El menú está completo desde el principio para poder ver el panel entero y discutir lo
| que falta mirándolo, en lugar de ir descubriendo entradas de a una. Lo que no está
| hecho lo dice esta pantalla, no el menú.
|
| Cómo se llama la pantalla lo dice el menú del servidor, así que el nombre está escrito
| en un solo lado. Ver PendienteController.
*/

type Props = {
    titulo: string;
    /** El desplegable del que cuelga, si cuelga de uno: «Cadetes», «Clientes». */
    dentroDe: string | null;
};

export default function Pendiente({ titulo, dentroDe }: Props) {
    return (
        <>
            <Head title={titulo} />

            <div className="p-4">
                {dentroDe && (
                    <p className="text-muted-foreground mb-1 text-sm">
                        {dentroDe}
                    </p>
                )}

                <Heading title={titulo} description="Todavía no está hecha." />

                <Card className="flex flex-col items-center gap-3 p-10 text-center">
                    <Construction className="text-muted-foreground size-8" />
                    <p className="text-muted-foreground max-w-prose text-sm">
                        El menú está completo para poder ver el panel entero, pero
                        esta pantalla todavía no se escribió. Por ahora se usa la
                        del sistema viejo.
                    </p>
                </Card>
            </div>
        </>
    );
}
