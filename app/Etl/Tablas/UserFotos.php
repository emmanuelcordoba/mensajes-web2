<?php

namespace App\Etl\Tablas;

use App\Etl\Archivos;
use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * user_fotos. Sale de la colección `users`, no de una propia: la foto es una
 * columna del usuario en el sistema viejo y una tabla hija en el nuevo (PERF-2).
 *
 * De los 9.238 usuarios, 1.615 tienen algo en `foto`:
 *
 * - **1.588 son data URI** y se decodifican a archivo. Son 411 MB de base64,
 *   entre 815 PNG y 773 JPEG.
 * - **27 son `assets/images/avatar.png`**, que no es un archivo subido sino el
 *   avatar por defecto de la aplicación vieja. Esas filas NO llevan
 *   `user_foto`: el sistema nuevo no tiene que arrastrar el placeholder de
 *   nadie, y el filtro los deja afuera desde MongoDB.
 *
 * ⚠️ La app de clientes lee esta foto en cada pedido —expone `['_id','foto']`
 * del usuario del cadete—, así que la API sigue devolviendo el data URI: lo
 * arma leyendo el archivo. Y la app de cadetes la escribe al editar el perfil,
 * así que la API también recibe un data URI y escribe el archivo.
 */
class UserFotos extends Migrador
{
    private Archivos $archivos;

    /** @var array<string, int>|null */
    private ?array $users = null;

    public function coleccion(): string
    {
        return 'users';
    }

    public function tabla(): string
    {
        return 'user_fotos';
    }

    /**
     * Sólo los usuarios con una foto de verdad. Descartar el placeholder acá y
     * no en `fila()` hace que `enOrigen()` cuente 1.588 y no 1.615, así que la
     * verificación del comando compara contra la cifra correcta.
     */
    public function filtro(): array
    {
        return ['foto' => ['$nin' => [null, '', 'assets/images/avatar.png']]];
    }

    public function campos(): array
    {
        return ['foto', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        $this->archivos ??= new Archivos;
        $this->users ??= MapaDeIds::mapa('users');

        $legacy = Origen::id($documento['_id'] ?? null);

        $user = ($legacy === null ? null : ($this->users[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Una foto pertenece al usuario «'.($legacy ?? 'ninguno').'», que no está en '
                .'migracion_ids. ¿Se cargó users antes?'
            );

        return [
            'user_id' => $user,
            // El nombre sale del id nuevo, no del viejo: el ObjectId no se
            // conserva en ninguna parte del sistema nuevo.
            'ruta_archivo' => $this->archivos->desdeDataUri(
                (string) $documento['foto'],
                Archivos::USER_FOTOS."/{$user}",
            ),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }
}
