import { Head } from '@inertiajs/react';

import CadeteForm, { type CadeteEditable } from '@/components/cadete-form';
import Heading from '@/components/heading';

export default function CadetesEdit({
    cadete,
    vehiculos,
}: {
    cadete: CadeteEditable;
    vehiculos: Record<string, string>;
}) {
    return (
        <>
            <Head title={cadete.nombre_completo} />

            <div className="max-w-3xl p-4">
                <Heading
                    title={cadete.nombre_completo}
                    description={`Móvil N° ${cadete.numero_movil}`}
                />

                <CadeteForm
                    cadete={cadete}
                    vehiculos={vehiculos}
                    action={`/panel/cadetes/${cadete.id}`}
                    method="patch"
                />
            </div>
        </>
    );
}

CadetesEdit.layout = {
    breadcrumbs: [{ title: 'Cadetes', href: '/panel/cadetes' }],
};
