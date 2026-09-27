<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un error que reportó una de las dos apps. Son 2.477.
 *
 * ⚠️ **`user_id` va sin clave foránea a propósito**, y es la única tabla del esquema
 * donde eso pasa: un error puede llegar de una sesión ya cerrada, o de un usuario que
 * después se borró. Frenar la carga de telemetría por un vínculo que se perdió sería
 * cambiar la prioridad de lugar, así que el ETL deja el `user_id` en NULL y sigue. En
 * todos los demás migradores, un vínculo que no resuelve frena.
 *
 * ⚠️ Por lo mismo **no hay relación `user()`**: la columna no garantiza que el usuario
 * exista. Quien lo quiera lo busca y maneja el null, en vez de confiar en una relación
 * que puede venir vacía sin que eso sea un error.
 *
 * ⚠️ `extra` queda como TEXT y **no se castea a `array`**, al revés que en el modelo
 * viejo. Lo que mandan las apps no siempre es JSON válido, y un cast que falla al leer
 * convierte un error ajeno en un error propio: justo lo que una tabla de errores no
 * tiene que hacer.
 *
 * @property int $id
 * @property string|null $message
 * @property string|null $source
 * @property string|null $context
 * @property string|null $extra
 * @property string|null $platform
 * @property string|null $app_version
 * @property int|null $user_id
 * @property string|null $user_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'message',
    'source',
    'context',
    'extra',
    'platform',
    'app_version',
    'user_id',
    'user_email',
])]
class ErrorLog extends Model
{
    public const CADETE_APP = 'cadete-app';

    public const CLIENTE_APP = 'cliente-app';

    public const DESCONOCIDA = 'unknown';

    protected $table = 'error_logs';
}
