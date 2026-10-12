<?php

namespace App\Services;

use App\Exceptions\MapaNoDisponible;
use App\Support\Ubicacion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lo que este proyecto necesita de Google Maps desde el SERVIDOR: resolver una dirección a un
 * punto, un punto a una dirección, y medir la distancia manejando entre dos puntos.
 *
 * ⚠️ **Es la única llamada HTTP saliente en el camino de un request.** Eso trae dos cosas que
 * quien llama tiene que tener en cuenta: latencia —una cotización son tres llamadas, cada una
 * con su tiempo de espera— y un modo de falla nuevo. Cuando Google no está se lanza
 * `MapaNoDisponible` y quien llama decide; **nunca se devuelve una distancia inventada**.
 *
 * ⚠️ **La clave puede no ser la del panel, y conviene que no lo sea.** Lee
 * `services.google.maps_servidor`, que cae a `maps_key` —la del navegador— cuando no está
 * configurada, porque así funciona hoy el sistema viejo: una sola clave para las dos cosas.
 * Eso anda, pero obliga a que esa clave no tenga restricción de aplicación, porque Google
 * deja elegir una sola —referrers HTTP, O IPs, O apps— y la API de JavaScript del panel sólo
 * tolera referrers mientras estas llamadas sólo toleran IPs. Con una clave propia, restringida
 * por IP y con sólo Geocoding y Directions habilitados, la del navegador puede pasar a estar
 * restringida por dominio, que es la que está publicada en el HTML. Ver `config/services.php`.
 *
 * ⚠️ **Por qué tres llamadas y no una**: validar la cobertura necesita provincia y localidad,
 * y eso sólo viene en los `address_components` del Geocoding API.
 */
class GoogleMaps
{
    public const GEOCODING = 'https://maps.googleapis.com/maps/api/geocode/json';

    public const DIRECTIONS = 'https://maps.googleapis.com/maps/api/directions/json';

    /** Lo que se espera a Google antes de darlo por caído. */
    public const SEGUNDOS_DE_ESPERA = 5;

    /**
     * Dirección en texto libre a punto.
     *
     * ⚠️ Se restringe por `components` y no se sesga por `bounds`: con `administrative_area`
     * como filtro duro, una calle que existe en otra provincia devuelve ZERO_RESULTS en lugar
     * de devolver el punto equivocado.
     *
     * @return Ubicacion|null Null si no se encontró.
     *
     * @throws MapaNoDisponible
     */
    public function geocodificar(string $direccion): ?Ubicacion
    {
        $provincia = config('cobertura.provincia');

        $respuesta = $this->pedir(self::GEOCODING, [
            'address' => $direccion,
            'components' => 'country:AR|administrative_area:'.(is_string($provincia) ? $provincia : ''),
            'region' => 'ar',
            ...$this->idioma(),
        ]);

        if ($respuesta === null) {
            return null;
        }

        return Ubicacion::desdeGeocoding($respuesta['results'][0]);
    }

    /**
     * Punto a dirección, para completar provincia y localidad cuando el consumidor manda
     * coordenadas en vez de texto. Sin esto no se podría validar la cobertura de un pedido
     * cotizado por coordenadas.
     *
     * @throws MapaNoDisponible
     */
    public function geocodificarCoordenadas(float $lat, float $lng): ?Ubicacion
    {
        $respuesta = $this->pedir(self::GEOCODING, [
            'latlng' => $lat.','.$lng,
            'region' => 'ar',
            ...$this->idioma(),
        ]);

        if ($respuesta === null) {
            return null;
        }

        $ubicacion = Ubicacion::desdeGeocoding($respuesta['results'][0]);

        // ⚠️ Las coordenadas mandadas mandan: la geocodificación inversa se usa sólo para
        // saber provincia y localidad, no para mover el punto. Por eso también se descarta el
        // placeId que vino: rutear por él movería el extremo al lugar que Google encontró
        // cerca, y el consumidor pidió exactamente estas coordenadas.
        $ubicacion->lat = $lat;
        $ubicacion->lng = $lng;
        $ubicacion->placeId = null;

        return $ubicacion;
    }

    /**
     * Distancia de la ruta a recorrer, en metros.
     *
     * Es el mismo número que la app saca de `routes[0].legs[0].distance.value` con
     * `travelMode: DRIVING`: la distancia manejando por el camino, no la línea recta ni la
     * duración. Y se pide igual que la app: por `place_id` cuando lo hay. Ver
     * `Ubicacion::comoParametro()`.
     *
     * @return int|null Null si no hay ruta entre los dos puntos.
     *
     * @throws MapaNoDisponible
     */
    public function distanciaEnMetros(Ubicacion $origen, Ubicacion $destino): ?int
    {
        $respuesta = $this->pedir(self::DIRECTIONS, [
            'origin' => $origen->comoParametro(),
            'destination' => $destino->comoParametro(),
            'mode' => 'driving',
            'region' => 'ar',
        ]);

        if ($respuesta === null) {
            return null;
        }

        $metros = $respuesta['routes'][0]['legs'][0]['distance']['value'] ?? null;

        return is_numeric($metros) ? (int) $metros : null;
    }

    /**
     * ⚠️ **Fijar el idioma no es cosmético**: la cobertura se valida comparando el
     * `long_name` de la provincia contra «Tucumán», y sin este parámetro Google elige el
     * idioma por su cuenta. Comprobado contra la API real: la misma provincia volvió como
     * «Tucumán» en unas consultas y como «Tucumán Province» en otras, y con la segunda forma
     * una dirección válida quedaba fuera de cobertura por una diferencia de texto.
     *
     * El navegador manda su locale; el servidor no tiene, así que hay que decírselo.
     *
     * @return array<string, string>
     */
    protected function idioma(): array
    {
        return ['language' => 'es'];
    }

    /** La clave del servidor, que cae a la del navegador si no hay una propia. */
    public static function clave(): ?string
    {
        $clave = config('services.google.maps_servidor');

        return is_string($clave) && $clave !== '' ? $clave : null;
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>|null Null cuando no hubo resultados.
     *
     * @throws MapaNoDisponible
     */
    protected function pedir(string $url, array $parametros): ?array
    {
        $clave = self::clave();

        if ($clave === null) {
            // Sin clave no se sale a la red: se avisa como indisponibilidad, que es lo que es
            // desde el punto de vista de quien llama.
            throw new MapaNoDisponible(
                'No hay clave de Google Maps configurada (GOOGLE_MAPS_SERVER_KEY ni GOOGLE_MAPS_KEY).'
            );
        }

        try {
            $respuesta = Http::timeout(self::SEGUNDOS_DE_ESPERA)
                ->get($url, [...$parametros, 'key' => $clave]);
        } catch (Throwable $e) {
            // ⚠️ No se propaga el mensaje: puede traer la URL completa, y ahí va la clave.
            throw new MapaNoDisponible('No se pudo consultar el servicio de mapas.');
        }

        if ($respuesta->failed()) {
            throw new MapaNoDisponible('El servicio de mapas contestó '.$respuesta->status().'.');
        }

        $cuerpo = $respuesta->json();

        if (! is_array($cuerpo)) {
            throw new MapaNoDisponible('El servicio de mapas contestó algo que no se pudo leer.');
        }

        $estado = $cuerpo['status'] ?? 'UNKNOWN_ERROR';

        if ($estado === 'ZERO_RESULTS' || $estado === 'NOT_FOUND') {
            return null;
        }

        if ($estado !== 'OK') {
            // OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST, UNKNOWN_ERROR. Se registran
            // porque los tres primeros son problemas de configuración o de cuota que hay que
            // ver, no ruido normal.
            Log::error('Google Maps respondió con un estado inesperado', [
                'status' => $estado,
                'error_message' => $cuerpo['error_message'] ?? null,
            ]);

            throw new MapaNoDisponible('El servicio de mapas respondió '.$estado.'.');
        }

        if (empty($cuerpo['results']) && empty($cuerpo['routes'])) {
            return null;
        }

        /** @var array<string, mixed> $cuerpo */
        return $cuerpo;
    }
}
