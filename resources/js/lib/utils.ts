import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * El texto de un campo del formulario.
 *
 * ⚠️ `FormData.get()` devuelve `string | File | null`, y `String(...)` sobre un `File` da
 * «[object File]»: eso viajaría al servidor como si fuera lo que la persona escribió, y en
 * la pantalla se vería ese literal metido en el filtro. Acá todos los campos son de texto,
 * así que un archivo —o nada— es cadena vacía.
 */
export function textoDelCampo(valor: FormDataEntryValue | null): string {
    return typeof valor === 'string' ? valor : '';
}
