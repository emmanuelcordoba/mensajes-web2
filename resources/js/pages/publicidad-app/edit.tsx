import { Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import PublicidadForm, {
    type PublicidadEditable,
} from '@/components/publicidad-form';

export default function PublicidadEdit({
    publicidad,
}: {
    publicidad: PublicidadEditable;
}) {
    return (
        <>
            <Head title="Editar publicidad" />

            <div className="max-w-2xl p-4">
                <Heading
                    title="Editar publicidad"
                    description="Si no elegís una imagen nueva, queda la que está."
                />

                <PublicidadForm
                    publicidad={publicidad}
                    action={`/panel/publicidad-app/${publicidad.id}`}
                    method="patch"
                />
            </div>
        </>
    );
}

PublicidadEdit.layout = {
    breadcrumbs: [{ title: 'Publicidad APP', href: '/panel/publicidad-app' }],
};
