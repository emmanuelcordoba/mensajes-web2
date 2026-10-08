<?php

namespace App\Http\Resources\Api\CadeteApp\V1;

use App\Models\Cadete;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un cadete, con la forma exacta que la app espera.
 *
 * ⚠️ **El contrato no es este archivo: es `src/app/interfaces/interfaces.ts` de la app**,
 * que declara campo por campo lo que lee. La app está publicada en los teléfonos y no se
 * actualiza a la par del servidor, así que la forma de esta respuesta no se negocia. Lo que
 * sigue son las tres decisiones que no son obvias.
 *
 * ⚠️ **`_id` y no `id`.** La app lee `_id` —era el de MongoDB— para rutear y para aceptar,
 * rechazar y finalizar un pedido. Sale la clave de Postgres con el nombre de allá.
 *
 * ⚠️ **Los ids van como TEXTO.** La app los declara `string`, y así salían de MongoDB. El
 * punto fino está en el chat: `mensaje.cadete.user_id !== mensaje.user_id` decide de qué
 * lado de la pantalla va cada globo, y es una comparación estricta. Si un id sale número y
 * el otro texto, la comparación da siempre verdadero y **todos los mensajes aparecen del
 * lado equivocado**. Por eso los ids se emiten todos igual, desde un solo lugar.
 *
 * ⚠️ **El saldo sale como texto y los demás números como números**, que es lo que la app
 * declara: `cobranza_saldo: string`, `dni: number`, `orden_cola?: number`.
 *
 * @mixin Cadete
 */
class CadeteDeLaApp extends JsonResource
{
    /**
     * Se pone aparte porque no es una columna: la calcula `posicionEnCola()` y sólo tiene
     * sentido en las respuestas que la app usa para dibujar la cola.
     */
    private ?int $posicion = null;

    public function conLaPosicionEnCola(): self
    {
        $this->posicion = $this->resource->posicionEnCola();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'numero_movil' => (string) $this->numero_movil,
            'apellidos' => $this->apellidos,
            'nombres' => $this->nombres,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            // Sólo la fecha: es un día, no un instante. La app la muestra y la manda de
            // vuelta al editar el perfil, así que entra y sale con el mismo formato.
            'fecha_nacimiento' => $this->fecha_nacimiento->format('Y-m-d'),
            // ⚠️ Entero, y en el sistema viejo convivían textos y números en esta misma
            // columna. Ver PANEL-15.
            'dni' => $this->dni === null ? null : (int) $this->dni,
            'tipo_vehiculo' => $this->tipo_vehiculo,
            'estado' => $this->estado,
            'ubicacion_lat' => $this->ubicacion_lat,
            'ubicacion_lon' => $this->ubicacion_lon,
            'orden_cola' => $this->orden_cola,
            'posicion_en_cola' => $this->posicion,
            'user_id' => (string) $this->user_id,
            'cobranza_saldo' => $this->cobranza_saldo,
            'modalidad_cobranza' => $this->modalidad_cobranza,
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
        ];
    }
}
