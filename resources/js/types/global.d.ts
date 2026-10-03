import type { Auth } from '@/types/auth';
import type { GrupoDeMenu, TiempoDeDemora } from '@/types/navigation';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            navegacion: GrupoDeMenu[];
            tiempoDeDemora: TiempoDeDemora | null;
            /** El mensaje que dejó el controlador en la sesión. Ver components/avisos. */
            aviso: { success: string | null; danger: string | null };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
