import { Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import UserForm, {
    type CuentaEditable,
    type RolDisponible,
} from '@/components/user-form';

export default function UsuariosEdit({
    roles,
    usuario,
}: {
    roles: RolDisponible[];
    usuario: CuentaEditable;
}) {
    return (
        <>
            <Head title={usuario.name} />

            <div className="max-w-3xl p-4">
                <Heading
                    title={usuario.name}
                    description="La contraseña sólo cambia si escribís una nueva."
                />

                <UserForm
                    roles={roles}
                    usuario={usuario}
                    action={`/panel/users/${usuario.id}`}
                    method="patch"
                />
            </div>
        </>
    );
}

UsuariosEdit.layout = {
    breadcrumbs: [{ title: 'Usuarios', href: '/panel/users' }],
};
