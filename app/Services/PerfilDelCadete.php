<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El perfil que el cadete edita desde la app: lo que se escribe y dónde.
 *
 * Son dos filas —el cadete y su cuenta— más, si cambió, el archivo de la foto. Van en una
 * transacción por lo mismo que el alta y la edición del panel: un email que choca contra el
 * índice no puede dejar los datos del cadete escritos y la cuenta sin tocar.
 *
 * ⚠️ **`users.name` se rearma con el nombre y el apellido**, como hacía el sistema viejo.
 * La app no muestra ese campo en ninguna pantalla, pero el panel sí: es el nombre con el que
 * la cuenta aparece en la lista de cuentas y en los mensajes del chat. Sin esto, un cadete
 * que se corrige el apellido queda con el viejo para la oficina.
 *
 * ⚠️ **La foto se guarda DESPUÉS de la transacción.** Escribir un archivo adentro de una
 * transacción no se deshace si el COMMIT falla: quedaría un archivo huérfano en el bucket.
 * Al revés —primero la transacción, después el archivo— lo peor que pasa es que la foto no
 * entre y el cadete la vuelva a mandar, que es lo que haría igual.
 */
class PerfilDelCadete
{
    /**
     * Los campos del cadete que la app puede cambiar. Lo que no está acá no se toca.
     *
     * @var list<string>
     */
    public const DEL_CADETE = [
        'nombres', 'apellidos', 'tipo_vehiculo', 'direccion', 'telefono',
    ];

    /**
     * Guarda el perfil y devuelve la cuenta con el cadete al día.
     *
     * La cuenta se recibe aparte y no se saca de `$cadete->user`: ese accesor es nullable
     * —una cuenta dada de baja deja al cadete sin ella—, y acá la cuenta es la que está
     * autenticada, así que existe por construcción.
     *
     * @param  array<string, mixed>  $datos  Ya validados por `GuardarPerfilRequest`.
     */
    public function guardar(User $cuenta, Cadete $cadete, array $datos): User
    {
        DB::transaction(function () use ($cadete, $cuenta, $datos): void {
            foreach (self::DEL_CADETE as $campo) {
                $cadete->{$campo} = $datos[$campo];
            }

            $cadete->fecha_nacimiento = $datos['fecha_nacimiento'];
            $cadete->dni = (int) $datos['dni'];
            $cadete->save();

            $cuenta->name = trim($datos['nombres'].' '.$datos['apellidos']);
            $cuenta->email = $datos['email'];
            $cuenta->save();
        });

        if (FotosDePerfil::esFotoNueva($datos['foto'] ?? null)) {
            (new FotosDePerfil)->guardar($cuenta, (string) $datos['foto']);
        }

        return $cuenta->load(['foto', 'cadete']);
    }
}
