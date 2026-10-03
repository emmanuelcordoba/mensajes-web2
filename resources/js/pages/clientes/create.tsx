import { Head } from '@inertiajs/react';

import ClienteForm from '@/components/cliente-form';
import Heading from '@/components/heading';

export default function ClientesCreate() {
    return (
        <>
            <Head title="Nuevo cliente" />

            <div className="max-w-3xl p-4">
                <Heading
                    title="Nuevo cliente"
                    description="El número se lo asigna el sistema al guardar."
                />

                <ClienteForm action="/panel/clientes" method="post" />
            </div>
        </>
    );
}

ClientesCreate.layout = {
    breadcrumbs: [
        { title: 'Clientes', href: '/panel/clientes' },
        { title: 'Nuevo', href: '/panel/clientes/create' },
    ],
};
