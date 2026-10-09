<?php

namespace App\Http\Resources\Api\CadeteApp\V1;

use App\Models\Cadete;
use App\Models\Mensaje;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Un globo del chat, con la forma exacta que la app espera.
 *
 * ⚠️ **ACÁ ESTÁ EL PUNTO MÁS FINO DE TODO EL CONTRATO.** La plantilla del chat decide de qué
 * lado de la pantalla va cada mensaje con esto:
 *
 *     *ngIf="mensaje.cadete.user_id !== mensaje.user_id"
 *
 * Es una comparación ESTRICTA entre dos ids. Si uno sale número y el otro texto, nunca son
 * iguales y **todos los mensajes aparecen del lado de la central**: el cadete abre el chat y
 * no encuentra nada de lo que escribió él. Por eso los dos salen de acá como texto, y por eso
 * los ids de todo este consumidor se emiten igual desde un solo lugar. En MongoDB eran
 * cadenas y nadie tuvo que pensarlo.
 *
 * ⚠️ **`mensaje.cadete` tiene que venir, o la plantilla revienta.** `mensaje.cadete.user_id`
 * sobre un `undefined` no es un globo en el lado equivocado: es un error de JavaScript que
 * deja la pantalla a medio dibujar.
 *
 * ⚠️ **Pero viene con dos campos y no con el cadete entero.** El modelo viejo declara
 * `$with = ['cadete.user', 'user']`, así que cada mensaje arrastra el cadete, la cuenta del
 * cadete y la cuenta de quien escribió. La plantilla lee de todo eso **un solo campo**, y el
 * cadete completo ya viaja una vez en `RespuestaMensajes.cadete`. La conversación más larga
 * son 351 mensajes y la pantalla se vuelve a pedir **cada 10 segundos** mientras está
 * abierta, con los datos del teléfono del cadete: repetir 351 veces un objeto que ya está en
 * la respuesta, para leerle un campo, es lo que no se copia. Es la misma decisión que en el
 * listado de pedidos.
 *
 * ⚠️ **`user` no se emite.** La app lo declara y no lo lee en ninguna parte, y es una cuenta
 * entera: es lo mismo que SEC-14.
 *
 * ⚠️ **`created_at` se imprime CRUDO** —`{{ mensaje.created_at }}`, sin pipe—, así que el
 * formato que salga de acá es el que el cadete ve debajo de cada globo. Ver `Momento::enApi`.
 *
 * @mixin Mensaje
 */
class MensajeDeLaApp extends JsonResource
{
    /**
     * El cadete de la conversación, que es de donde sale el `user_id` con el que se compara.
     *
     * No se saca de la relación a propósito: todos los mensajes de una respuesta son de la
     * misma conversación, y cargarla por mensaje serían 351 consultas para repetir 351 veces
     * el mismo dato. Además `Mensaje::$cadete` es nullable —el cadete puede estar dado de
     * baja—, y acá el cadete es el del token, así que existe.
     */
    private ?Cadete $cadete = null;

    public function deLaConversacionDe(Cadete $cadete): self
    {
        $this->cadete = $cadete;

        return $this;
    }

    /**
     * Una conversación entera.
     *
     * @param  Collection<int, Mensaje>  $mensajes
     * @return array<int, self>
     */
    public static function conversacion(Collection $mensajes, Cadete $cadete): array
    {
        return $mensajes
            ->map(fn (Mensaje $mensaje): self => (new self($mensaje))->deLaConversacionDe($cadete))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        if ($this->cadete === null) {
            // Sin esto el `cadete` saldría vacío y la plantilla del chat reventaría, que es
            // justo lo que no se puede dejar pasar en silencio.
            throw new RuntimeException(
                'Un mensaje se emite siempre diciendo de qué conversación es: usá deLaConversacionDe().'
            );
        }

        return [
            '_id' => (string) $this->id,
            'mensaje' => $this->mensaje,
            'cadete_id' => (string) $this->cadete_id,
            // ⚠️ Texto, los dos, y de un solo lugar: es la comparación estricta de arriba.
            'user_id' => (string) $this->user_id,
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
            // Los dos campos que la plantilla necesita para saber de qué lado va el globo.
            'cadete' => [
                '_id' => (string) $this->cadete->id,
                'user_id' => (string) $this->cadete->user_id,
            ],
        ];
    }
}
