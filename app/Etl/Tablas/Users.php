<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * users. Va después de roles, porque la referencia, y antes de clientes.
 *
 * ⚠️ El nudo circular. `users.cliente_restringido_id` apunta a `clientes` y
 * `clientes.user_id` apunta a `users`. Acá la columna se carga en NULL y la
 * resuelve un UPDATE después de cargar clientes. Son 2 usuarios en producción,
 * pero el orden vale igual: la clave foránea existe desde la migración.
 *
 * ⚠️ El email se normaliza, y eso hace chocar cuentas. El esquema nuevo guarda
 * el email en minúsculas y lo exige con un CHECK, así que este migrador baja y
 * recorta. En MongoDB conviven 77 grupos que sólo difieren en mayúsculas —56
 * entre cuentas activas—, y al normalizar violan el UNIQUE. Ver DATA-7: hay que
 * unificarlos antes del ETL, y `problemas()` no deja arrancar hasta entonces.
 *
 * Tres columnas del sistema viejo no se migran: `foto` se va a `user_fotos`,
 * `facebook_id` quedó sin uso cuando DEP-1 borró los endpoints de Facebook, y
 * `api_token` lo sacó SEC-5 —guardaba el JWT en texto plano—.
 *
 * Y dos columnas nacen NULL en todos: `codigo_generado_at`, que es nueva y no
 * tiene equivalente viejo, y el trío de doble factor de Fortify, que tampoco.
 */
class Users extends Migrador
{
    /**
     * El mapa de roles, que se lee una vez y se usa 9.238 veces.
     *
     * @var array<string, int>|null
     */
    private ?array $roles = null;

    public function coleccion(): string
    {
        return 'users';
    }

    public function tabla(): string
    {
        return 'users';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return [
            'name', 'email', 'password', 'remember_token', 'email_verified_at',
            'iniciales', 'color', 'codigo_de_verificacion',
            'bloqueado', 'bloqueado_mensaje', 'rol_id',
            'created_at', 'updated_at', 'deleted_at',
        ];
    }

    public function fila(array $documento): ?array
    {
        $this->roles ??= MapaDeIds::mapa('roles');

        return [
            'name' => trim((string) ($documento['name'] ?? '')),
            'email' => self::email($documento['email'] ?? ''),
            'password' => self::texto($documento['password'] ?? null),
            'remember_token' => self::texto($documento['remember_token'] ?? null),
            'email_verified_at' => Origen::fecha($documento['email_verified_at'] ?? null),
            'iniciales' => self::texto($documento['iniciales'] ?? null),
            'color' => self::texto($documento['color'] ?? null),
            'codigo_de_verificacion' => self::entero($documento['codigo_de_verificacion'] ?? null),
            'codigo_generado_at' => null,
            'bloqueado' => self::texto($documento['bloqueado'] ?? null),
            'bloqueado_mensaje' => self::texto($documento['bloqueado_mensaje'] ?? null),
            'rol_id' => $this->rol($documento),
            'cliente_restringido_id' => null,
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }

    /**
     * Lo que el esquema va a rechazar, visto en el origen antes de escribir.
     *
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $vistos = [];

        foreach ($this->origen->documentos($this->coleccion(), [], ['email', 'deleted_at']) as $documento) {
            $email = self::email($documento['email'] ?? '');

            if ($email === null) {
                continue;
            }

            $vistos[$email] ??= ['total' => 0, 'activos' => 0];
            $vistos[$email]['total']++;
            $vistos[$email]['activos'] += isset($documento['deleted_at']) ? 0 : 1;
        }

        $grupos = array_filter($vistos, static fn (array $v): bool => $v['total'] > 1);

        if ($grupos === []) {
            return [];
        }

        $entreActivos = array_filter($grupos, static fn (array $v): bool => $v['activos'] > 1);

        // No se listan los emails: son datos personales y el informe va a la
        // consola. Quién es cada uno lo resuelve la pantalla de DATA-7.
        return [sprintf(
            '%d emails quedan repetidos al normalizarlos, %d de ellos entre cuentas activas. '
            .'users.email es UNIQUE: hay que unificarlos antes del ETL (DATA-7).',
            count($grupos),
            count($entreActivos),
        )];
    }

    /**
     * El id nuevo del rol. Que falte es válido —20 usuarios no tienen rol—,
     * pero que esté y no se pueda resolver no: dejaría al usuario sin rol en
     * silencio, y el rol es lo que decide qué puede hacer.
     *
     * @param  array<string, mixed>  $documento
     */
    private function rol(array $documento): ?int
    {
        $legacy = Origen::id($documento['rol_id'] ?? null);

        if ($legacy === null) {
            return null;
        }

        return $this->roles[$legacy] ?? throw new RuntimeException(
            "El usuario apunta al rol «{$legacy}», que no está en migracion_ids. "
            .'¿Se cargó roles antes?'
        );
    }

    /**
     * El email como lo exige users_email_normalizado_check: minúsculas y sin
     * espacios alrededor.
     */
    private static function email(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $email = mb_strtolower(trim($valor));

        return $email === '' ? null : $email;
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }

    private static function entero(mixed $valor): ?int
    {
        return is_int($valor) || is_float($valor) ? (int) $valor : null;
    }
}
