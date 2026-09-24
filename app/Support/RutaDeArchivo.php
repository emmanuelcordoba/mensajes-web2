<?php

namespace App\Support;

/**
 * The shape of a stored file path: the three image tables keep a path, not the
 * image (PERF-2, see ESQUEMA.sql, "Las imágenes son archivos").
 *
 * A path is joined to a root directory to serve the file, so an absolute one, or
 * one that climbs out with '..', is a traversal waiting to happen. The rule lives
 * here once instead of in each migration, so the three CHECK constraints cannot
 * drift apart, and so validation can reuse it when the panel starts writing these
 * rows.
 */
final class RutaDeArchivo
{
    /**
     * The PostgreSQL expression, for a CHECK constraint on a `ruta_archivo`
     * column.
     */
    public const CHECK = "ruta_archivo ~ '^[A-Za-z0-9][A-Za-z0-9/._-]*$' AND ruta_archivo NOT LIKE '%..%'";

    /**
     * The same rule in PHP, for whoever writes one of these rows.
     */
    public static function esValida(string $ruta): bool
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9/._-]*$#', $ruta) === 1
            && ! str_contains($ruta, '..');
    }
}
