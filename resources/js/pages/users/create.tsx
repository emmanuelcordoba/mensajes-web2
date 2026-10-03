import { Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import UserForm, { type RolDisponible } from '@/components/user-form';

export default function UsuariosCreate({ roles }: { roles: RolDisponible[] }) {
    return (
        <>
            <Head title="Nuevo usuario" />

            <div className="max-w-3xl p-4">
                <Heading
                    title="Nuevo usuario"
                    description="La cuenta con la que alguien entra al panel."
                />

                <UserForm roles={roles} action="/panel/users" method="post" />
            </div>
        </>
    );
}

UsuariosCreate.layout = {
    breadcrumbs: [
        { title: 'Usuarios', href: '/panel/users' },
        { title: 'Nuevo', href: '/panel/users/create' },
    ],
};
