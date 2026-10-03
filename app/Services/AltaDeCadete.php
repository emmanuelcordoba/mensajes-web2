<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dar de alta un cadete.
 *
 * ⚠️ UN CADETE NO ES UNA FILA: `cadetes.user_id` es NOT NULL, así que todo cadete nace
 * con una cuenta —la que usa para entrar a la app—. Son dos escrituras, y por eso esto
 * es un servicio y no un formulario: envueltas en una transacción, para que no quede una
 * cuenta de cadete sin cadete si la segunda falla. El panel viejo las hacía encadenadas
 * y sin transacción, porque MongoDB corría standalone y no había otra.
 *
 * ⚠️ El rol de la cuenta se pone acá y no viene del formulario. En el sistema viejo el
 * alta y la edición pasaban `$request->all()` al modelo, y como `rol_id` está en el
 * `fillable` de User alcanzaba con agregar ese campo al cuerpo para que la cuenta de un
 * cadete naciera administrador. Ver SEC-4.
 */
class AltaDeCadete
{
    /**
     * @param  array<string, mixed>  $datos  Lo que el formulario del panel edita.
     */
    public function alta(array $datos): Cadete
    {
        $rol = Rol::query()->where('rol', Rol::CADETE)->first();

        if ($rol === null) {
            throw new RuntimeException('No existe el rol de cadete: sin él la cuenta no podría entrar a la app.');
        }

        return DB::transaction(function () use ($datos, $rol): Cadete {
            $user = new User;
            $user->name = trim(($datos['nombres'] ?? '').' '.($datos['apellidos'] ?? ''));
            $user->email = (string) $datos['email'];
            // El cast `hashed` del modelo lo encripta; acá llega en claro.
            $user->password = (string) $datos['password'];
            $user->rol_id = $rol->id;
            $user->save();

            $cadete = new Cadete;
            $cadete->fill([
                'numero_movil' => (int) $datos['numero_movil'],
                'apellidos' => $datos['apellidos'],
                'nombres' => $datos['nombres'],
                'direccion' => $datos['direccion'],
                'telefono' => $datos['telefono'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'tipo_vehiculo' => $datos['tipo_vehiculo'],
                // Nace inactivo: lo activa él desde la app o la central desde la cola.
                'estado' => Cadete::ESTADO_INACTIVO,
                // ⚠️ Sin esto el cadete nace sin modalidad y no aparece en ninguno de los
                // dos listados de cobranzas, así que no hay forma de asignársela desde el
                // panel. Ver PANEL-8. Acá la columna tiene DEFAULT, pero se pone
                // explícita para que el alta no dependa de eso.
                'modalidad_cobranza' => $datos['modalidad_cobranza'] ?? Cadete::COBRANZA_SEMANAL,
                'tiene_monto_semanal_personal' => (bool) ($datos['tiene_monto_semanal_personal'] ?? false),
                // Vacío es null y no 0: la columna es nullable a propósito.
                'dni' => filled($datos['dni'] ?? null) ? (int) $datos['dni'] : null,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);
            $cadete->user_id = $user->id;
            $cadete->save();

            return $cadete;
        });
    }
}
