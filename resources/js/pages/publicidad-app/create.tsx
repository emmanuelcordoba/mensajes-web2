import { Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import PublicidadForm from '@/components/publicidad-form';

export default function PublicidadCreate() {
    return (
        <>
            <Head title="Nueva publicidad" />

            <div className="max-w-2xl p-4">
                <Heading
                    title="Nueva publicidad"
                    description="Va al carrusel de la app de clientes."
                />

                <PublicidadForm action="/panel/publicidad-app" method="post" />
            </div>
        </>
    );
}

PublicidadCreate.layout = {
    breadcrumbs: [
        { title: 'Publicidad APP', href: '/panel/publicidad-app' },
        { title: 'Nueva', href: '/panel/publicidad-app/create' },
    ],
};
