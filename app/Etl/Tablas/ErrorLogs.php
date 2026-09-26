<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;

/**
 * error_logs. 2.477 errores de JavaScript reportados por el panel y las apps.
 *
 * Es telemetría, no datos del negocio: se puede truncar sin consecuencias, y el
 * ETL la trata en consecuencia.
 *
 * ⚠️ `user_id` queda SIN clave foránea a propósito —un error puede llegar de una
 * sesión ya cerrada o de un usuario que después se borra, y perder el reporte
 * por eso sería peor que conservarlo huérfano—. Y por lo mismo, **acá sí se deja
 * NULL lo que no resuelve**, al revés que en el resto de los migradores: que un
 * reporte de error pierda a su usuario no rompe nada, y frenar la migración
 * entera por telemetría sería desproporcionado.
 *
 * En la copia del 2026-09-23 los 2.477 resuelven, así que el caso no se da.
 *
 * `source` es una columna nueva —la tenía el modelo y no el esquema— y la traen
 * 1.363 de los 2.477 documentos.
 */
class ErrorLogs extends Migrador
{
    /** @var array<string, int>|null */
    private ?array $users = null;

    public function coleccion(): string
    {
        return 'error_logs';
    }

    public function tabla(): string
    {
        return 'error_logs';
    }

    public function campos(): array
    {
        return ['message', 'source', 'context', 'extra', 'platform', 'app_version',
            'user_id', 'user_email', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        $this->users ??= MapaDeIds::mapa('users');

        $legacy = Origen::id($documento['user_id'] ?? null);

        return [
            'message' => self::texto($documento['message'] ?? null),
            'source' => self::texto($documento['source'] ?? null),
            'context' => self::texto($documento['context'] ?? null),
            'extra' => self::texto($documento['extra'] ?? null),
            'platform' => self::texto($documento['platform'] ?? null),
            'app_version' => self::texto($documento['app_version'] ?? null),
            'user_id' => $legacy === null ? null : ($this->users[$legacy] ?? null),
            'user_email' => self::texto($documento['user_email'] ?? null),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }
}
