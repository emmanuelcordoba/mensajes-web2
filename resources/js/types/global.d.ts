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
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
