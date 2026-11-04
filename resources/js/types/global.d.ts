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
            /**
             * ⚠️ Opcional: NO es una prop compartida. La manda sólo la portada del panel,
             * que es la única pantalla que lleva el selector, igual que en el sistema
             * viejo. En el resto llega `undefined`. Ver InicioController::tiempoDeDemora().
             */
            tiempoDeDemora?: TiempoDeDemora | null;
            /** El mensaje que dejó el controlador en la sesión. Ver components/avisos. */
            aviso: { success: string | null; danger: string | null };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
