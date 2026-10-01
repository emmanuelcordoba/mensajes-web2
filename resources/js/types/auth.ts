/**
 * Lo que el frontend sabe de quien está mirando.
 *
 * ⚠️ Esto tenía `[key: string]: unknown` al final, y ahí se escondía el problema:
 * el backend compartía el modelo entero —`bloqueado`, `bloqueado_mensaje`,
 * `codigo_generado_at`, `rol_id`, `cliente_restringido_id`, `deleted_at`— y el tipo
 * decía «y cualquier otra cosa», así que nada lo señalaba. Sin el índice abierto,
 * el tipo y lo que manda `HandleInertiaRequests` tienen que coincidir, y TypeScript
 * avisa cuando no.
 *
 * `two_factor_enabled` también estaba acá y **no lo producía ni lo leía nadie**: la
 * pantalla de seguridad lo recibe como prop propia de su controlador. Se fue.
 *
 * `avatar` se queda aunque hoy nadie lo mande: `user-info.tsx` y `app-header.tsx` lo
 * leen y caen en las iniciales. Las fotos existen —725 migradas— y servirlas
 * necesita el endpoint con URL firmada que todavía no está.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    /** El nombre del rol, no `rol_id`: es con lo que el panel decide qué mostrar. */
    rol: string | null;
    avatar?: string;
};

export type Auth = {
    user: User | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
