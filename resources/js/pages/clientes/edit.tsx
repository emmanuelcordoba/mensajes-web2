import { Head } from '@inertiajs/react';

import ClienteForm, { type ClienteEditable } from '@/components/cliente-form';
import Heading from '@/components/heading';

export default function ClientesEdit({
    cliente,
}: {
    cliente: ClienteEditable;
}) {
    return (
        <>
            <Head title={cliente.nombre_mostrado} />

            <div className="max-w-3xl p-4">
                <Heading
                    title={cliente.nombre_mostrado}
                    description={`N° ${cliente.numero}${cliente.de_la_app ? ' · vino de la app' : ''}`}
                />

                <ClienteForm
                    cliente={cliente}
                    action={`/panel/clientes/${cliente.id}`}
                    method="patch"
                />
            </div>
        </>
    );
}

ClientesEdit.layout = {
    breadcrumbs: [{ title: 'Clientes', href: '/panel/clientes' }],
};
