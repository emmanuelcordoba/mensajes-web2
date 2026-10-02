import type { Auth } from '@/types/auth';
import type { GrupoDeMenu } from '@/types/navigation';

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
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
