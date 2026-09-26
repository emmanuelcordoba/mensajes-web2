<?php

namespace App\Etl\Tablas;

use App\Etl\Archivos;
use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * publicidad_app_imagenes. Sale de `publicidadesapp`, igual que su padre: la
 * imagen es una columna allá y una tabla hija acá (PERF-2).
 *
 * Son las 21 publicidades del carrusel, 10 MB de base64 entre 12 PNG y 9 JPEG.
 *
 * ⚠️ La app de clientes las lee en `getSetup`, v1 y v2, y el modelo viejo no
 * tiene `$hidden`, así que hoy **el base64 entero viaja en el JSON**. La API
 * nueva sigue devolviendo lo mismo armando el data URI desde el archivo, porque
 * no se puede contar con que todos actualicen a tiempo.
 */
class PublicidadAppImagenes extends Migrador
{
    private Archivos $archivos;

    /** @var array<string, int>|null */
    private ?array $publicidades = null;

    public function coleccion(): string
    {
        return 'publicidadesapp';
    }

    public function tabla(): string
    {
        return 'publicidad_app_imagenes';
    }

    public function filtro(): array
    {
        return ['img_base64' => ['$nin' => [null, '']]];
    }

    public function campos(): array
    {
        return ['img_base64', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        $this->archivos ??= new Archivos;
        $this->publicidades ??= MapaDeIds::mapa('publicidades_app');

        $legacy = Origen::id($documento['_id'] ?? null);

        $publicidad = ($legacy === null ? null : ($this->publicidades[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Una imagen pertenece a la publicidad «'.($legacy ?? 'ninguna').'», que no está en '
                .'migracion_ids. ¿Se cargó publicidades_app antes?'
            );

        return [
            'publicidad_id' => $publicidad,
            // El nombre sale del ObjectId, no del id nuevo. Ver Archivos.
            'ruta_archivo' => $this->archivos->desdeDataUri(
                (string) $documento['img_base64'],
                Archivos::PUBLICIDAD_IMAGENES."/{$legacy}",
            ),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }
}
