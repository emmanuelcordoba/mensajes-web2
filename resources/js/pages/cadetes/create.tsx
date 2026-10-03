import { Head } from '@inertiajs/react';

import CadeteForm from '@/components/cadete-form';
import Heading from '@/components/heading';

export default function CadetesCreate({
    vehiculos,
    siguiente_movil,
}: {
    vehiculos: Record<string, string>;
    siguiente_movil: number;
}) {
    return (
        <>
            <Head title="Nuevo cadete" />

            <div className="max-w-3xl p-4">
                <Heading
                    title="Nuevo cadete"
                    description="Nace inactivo y con su cuenta de la app. Lo pone en la cola la central, o él desde la app."
                />

                <CadeteForm
                    vehiculos={vehiculos}
                    siguienteMovil={siguiente_movil}
                    action="/panel/cadetes"
                    method="post"
                />
            </div>
        </>
    );
}

CadetesCreate.layout = {
    breadcrumbs: [
        { title: 'Cadetes', href: '/panel/cadetes' },
        { title: 'Nuevo', href: '/panel/cadetes/create' },
    ],
};
