<?php

namespace App\Support;

/**
 * Un punto ya resuelto: coordenadas más lo que hace falta para decidir si está dentro del
 * área de cobertura.
 *
 * Existe porque validar la cobertura necesita provincia y localidad, y eso sólo viene en los
 * `address_components` del Geocoding API. Directions devuelve `geocoded_waypoints`, que no
 * los trae: por eso una cotización cuesta **tres** llamadas —dos geocodificaciones y una
 * ruta— y no una.
 *
 * ⚠️ Vive en `Support` y no en `Services`, donde estaba: no hace nada, es un dato con forma.
 */
class Ubicacion
{
    public ?float $lat = null;

    public ?float $lng = null;

    public ?string $provincia = null;

    public ?string $localidad = null;

    public ?string $direccionFormateada = null;

    /**
     * El identificador que Google le da al lugar.
     *
     * ⚠️ **Importa para rutear, y no da lo mismo que las coordenadas.** La app de clientes le
     * pasa a Directions un `placeId`; un placeId de un comercio conoce su acceso, mientras
     * que un par de coordenadas se engancha al segmento de calle más cercano. La misma
     * dirección puede dar una ruta —y un precio— distinto según cómo se la pida.
     */
    public ?string $placeId = null;

    /**
     * Google marca `partial_match` cuando no encontró la dirección exacta y devolvió lo más
     * parecido. Para cotizar eso no sirve: daría un precio calculado sobre otra dirección sin
     * que nadie se entere.
     */
    public bool $coincidenciaParcial = false;

    /**
     * @param  array<string, mixed>  $resultado  Un elemento de `results` del Geocoding API.
     */
    public static function desdeGeocoding(array $resultado): self
    {
        $ubicacion = new self;

        $lat = $resultado['geometry']['location']['lat'] ?? null;
        $lng = $resultado['geometry']['location']['lng'] ?? null;

        $ubicacion->lat = is_numeric($lat) ? (float) $lat : null;
        $ubicacion->lng = is_numeric($lng) ? (float) $lng : null;
        $ubicacion->direccionFormateada = self::texto($resultado['formatted_address'] ?? null);
        $ubicacion->placeId = self::texto($resultado['place_id'] ?? null);
        $ubicacion->coincidenciaParcial = (bool) ($resultado['partial_match'] ?? false);

        $componentes = $resultado['address_components'] ?? [];

        if (! is_array($componentes)) {
            return $ubicacion;
        }

        foreach ($componentes as $componente) {
            $tipos = is_array($componente) ? ($componente['types'] ?? []) : [];

            if (! is_array($tipos)) {
                continue;
            }

            if (in_array('administrative_area_level_1', $tipos, true)) {
                $ubicacion->provincia = self::texto($componente['long_name'] ?? null);
            }

            if (in_array('locality', $tipos, true)) {
                $ubicacion->localidad = self::texto($componente['long_name'] ?? null);
            }
        }

        return $ubicacion;
    }

    /**
     * El criterio de la app de clientes: provincia exacta Y localidad dentro de la lista.
     * Ver `config/cobertura.php`.
     */
    public function estaEnCobertura(): bool
    {
        $provincia = config('cobertura.provincia');

        if (self::normalizar($this->provincia) !== self::normalizar(is_string($provincia) ? $provincia : null)) {
            return false;
        }

        $cubiertas = array_map(self::normalizar(...), self::localidades());

        return in_array(self::normalizar($this->localidad), $cubiertas, true);
    }

    /**
     * Forma comparable de un nombre de lugar: sin acentos, sin mayúsculas y sin espacios de
     * más.
     *
     * ⚠️ Lo que Google devuelve en `address_components` es texto **para mostrar**, no un
     * identificador —el identificador es el `place_id`—, así que compararlo carácter por
     * carácter ata la cobertura a un formato que no controlamos. Hoy los cinco nombres
     * coinciden exactos con la configuración, pero ya pasó una vez que la provincia volviera
     * como «Tucumán Province» en vez de «Tucumán». Esto no arregla aquello —para eso está
     * `language=es`— pero evita que un acento o una mayúscula dejen un pedido fuera de
     * cobertura.
     */
    public static function normalizar(?string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower((string) $texto), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', $sinAcentos));
    }

    /**
     * Cómo se le nombra este punto a Directions.
     *
     * Se prefiere el `placeId`, que es lo que usa la app de clientes: así las dos vías rutean
     * igual y cotizan igual. Las coordenadas quedan para cuando el consumidor las mandó
     * explícitamente, porque ahí pidió ese punto y no otro.
     */
    public function comoParametro(): string
    {
        if ($this->placeId !== null && $this->placeId !== '') {
            return 'place_id:'.$this->placeId;
        }

        return $this->lat.','.$this->lng;
    }

    /**
     * Las localidades cubiertas, como lista de texto.
     *
     * @return list<string>
     */
    public static function localidades(): array
    {
        $localidades = config('cobertura.localidades');

        if (! is_array($localidades)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $l): string => (string) $l, $localidades));
    }

    protected static function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
