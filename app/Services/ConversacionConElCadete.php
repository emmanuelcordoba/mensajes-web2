<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * El chat entre la central y un cadete: quién escribió y quién ya leyó.
 *
 * ⚠️ **Los dos «leído» son una sola regla mirada desde dos lados**, y por eso están acá y no
 * repartidas. Un mensaje nace leído del lado del que lo escribe y sin leer del otro:
 * `leido_web = true, leido_app = false` cuando lo manda la central, al revés cuando lo manda
 * el cadete. En el sistema viejo esos cuatro valores están escritos a mano en dos
 * controladores que no se conocen —`Panel\MensajeController` y `Api\v1\MensajeController`—, y
 * el día que uno de los dos se equivoque el mensaje aparece leído sin que nadie lo haya
 * visto: el otro lado no se enteraría nunca de que le escribieron. Hay un test que compara
 * los dos lados.
 *
 * ⚠️ **Y el mensaje va SIEMPRE a la conversación propia.** Es SEC-17: antes, si el cuerpo de
 * la petición traía un `cadete_id`, el mensaje se guardaba en la conversación de ese otro
 * cadete y la central lo veía como escrito por él. La app nunca lo manda, pero el que tiene
 * un token sí puede. Acá el cadete no es un parámetro del cuerpo: es el del token.
 */
class ConversacionConElCadete
{
    /** El cadete escribe desde la app. */
    public function escribeElCadete(Cadete $cadete, User $cuenta, string $texto): Mensaje
    {
        return Mensaje::query()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $cuenta->id,
            'mensaje' => $texto,
            // Lo que escribe el cadete ya está leído de este lado, y no del otro.
            'leido_web' => false,
            'leido_app' => true,
        ]);
    }

    /** Y la central desde el panel. */
    public function escribeLaCentral(Cadete $cadete, User $cuenta, string $texto): Mensaje
    {
        return Mensaje::query()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $cuenta->id,
            'mensaje' => $texto,
            'leido_web' => true,
            'leido_app' => false,
        ]);
    }

    /**
     * Marca como vistos los que el cadete acaba de abrir en la app.
     *
     * ⚠️ **Una sola sentencia.** El sistema viejo traía los modelos y los guardaba uno por
     * uno dentro de un `foreach`: tantos UPDATE como mensajes sin leer, cada uno con su ida
     * y vuelta a la base, y además tocando `updated_at` de un mensaje que nadie editó.
     *
     * @return int Cuántos se marcaron.
     */
    public function losLeyoElCadete(Cadete $cadete): int
    {
        return Mensaje::query()
            ->where('cadete_id', $cadete->id)
            ->sinLeerEnLaApp()
            ->update(['leido_app' => true]);
    }

    /** Lo mismo del otro lado: la central abrió la conversación en el panel. */
    public function losLeyoLaCentral(Cadete $cadete): int
    {
        return Mensaje::query()
            ->where('cadete_id', $cadete->id)
            ->sinLeerEnLaWeb()
            ->update(['leido_web' => true]);
    }

    /**
     * La conversación entera, del más viejo al más nuevo.
     *
     * ⚠️ **El orden es explícito, y el sistema viejo no lo pedía.** Allá
     * `Mensaje::where('cadete_id', $x)->get()` sale en el orden en que la base quiera
     * devolverlo, que sin `ORDER BY` no está especificado. En MongoDB coincidía con el de
     * inserción y nadie tuvo que pensarlo.
     *
     * Y acá el de inserción NO es el cronológico: el ETL cargó los mensajes en el orden en
     * que se los dio Mongo, no por fecha. Medido sobre las filas migradas, **95 de las 444
     * conversaciones tienen mensajes cuyo id avanza mientras la fecha retrocede**, 200 pares
     * en total. Para esas 95, leer «en el orden en que salga» es leer una conversación
     * desordenada: una respuesta antes de la pregunta.
     *
     * `id` como segundo criterio desempata dos mensajes del mismo segundo, que son **52
     * grupos** en los datos de hoy: es lo que pasa cuando la central contesta con dos
     * renglones seguidos.
     *
     * @return Collection<int, Mensaje>
     */
    public function mensajes(Cadete $cadete): Collection
    {
        return Mensaje::query()
            ->where('cadete_id', $cadete->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los que el cadete todavía no vio.
     *
     * @return Collection<int, Mensaje>
     */
    public function sinLeerPorElCadete(Cadete $cadete): Collection
    {
        return Mensaje::query()
            ->where('cadete_id', $cadete->id)
            ->sinLeerEnLaApp()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
