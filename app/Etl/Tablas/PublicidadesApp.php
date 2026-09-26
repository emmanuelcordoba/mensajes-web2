<?php

namespace App\Etl\Tablas;

use App\Etl\Migrador;
use App\Etl\Origen;

/**
 * publicidades_app. Las 21 publicidades del carrusel de la app. La colección se
 * llama `publicidadesapp` en el sistema viejo, porque el modelo lo declara.
 *
 * ⚠️ `img_base64` NO se migra acá: la imagen va a `publicidad_app_imagenes`,
 * que guarda la ruta de un archivo (PERF-2). Las 21 la tienen, y la app de
 * clientes las lee en `getSetup` — el contrato de la API se conserva armando el
 * data URI al responder, no guardándolo.
 *
 * `anotaIds()` es true porque la tabla de imágenes las referencia.
 */
class PublicidadesApp extends Migrador
{
    public function coleccion(): string
    {
        return 'publicidadesapp';
    }

    public function tabla(): string
    {
        return 'publicidades_app';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return ['url', 'visible', 'created_at', 'updated_at', 'deleted_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'url' => trim((string) ($documento['url'] ?? '')),
            // El panel las crea visibles; ausente entra como el default.
            'visible' => (bool) ($documento['visible'] ?? true),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }
}
