<?php

namespace App\Services;

use App\Exceptions\MapaNoDisponible;
use App\Support\Ubicacion;

/**
 * Cotizar un viaje a partir de dos direcciones: ubicarlas, validarlas y medir.
 *
 * Es el corazón de la API de integración, y está acá y no en el controlador porque **los dos
 * endpoints hacen exactamente esto**: cotizar lo devuelve, y el alta lo repite para no creerle
 * el precio a nadie. Es la diferencia con el alta de la app de clientes, que acepta el `costo`
 * que le manda el teléfono (ver COT-1).
 *
 * ⚠️ **Devuelve las ubicaciones resueltas junto con el precio**, y eso no es comodidad: sin
 * ellas el alta tendría que volver a geocodificar los dos extremos para guardar la dirección,
 * o sea dos llamadas facturadas de más por cada pedido.
 *
 * ⚠️ **Los seis motivos de rechazo son distintos a propósito.** Un consumidor externo no
 * tiene a quién preguntarle: la diferencia entre «no se pudo ubicar», «es ambigua», «está
 * fuera de cobertura» y «no existe en la localidad que declaraste» es lo que le dice qué
 * corregir.
 */
class CotizacionDeViaje
{
    public function __construct(protected GoogleMaps $mapas, protected Cotizador $cotizador) {}

    /**
     * El precio del viaje, o el motivo por el que no se puede dar.
     *
     * @param  array<string, mixed>  $datos  Ya validados.
     * @return array{error: string|null, costo: string|null, distancia: int|null, origen: Ubicacion|null, destino: Ubicacion|null}
     *
     * @throws MapaNoDisponible
     */
    public function para(array $datos): array
    {
        $origen = $this->resolver($datos, 'origen');

        if ($origen === null) {
            return self::rechazo('No se pudo ubicar la dirección de origen.');
        }

        $destino = $this->resolver($datos, 'destino');

        if ($destino === null) {
            return self::rechazo('No se pudo ubicar la dirección de destino.', $origen);
        }

        foreach (['origen' => $origen, 'destino' => $destino] as $punto => $ubicacion) {
            // ⚠️ Google marca `partial_match` cuando devolvió lo más parecido en vez de lo
            // pedido. Cotizar sobre eso sería poner un precio calculado sobre otra dirección
            // sin que nadie se entere.
            if ($ubicacion->coincidenciaParcial) {
                return self::rechazo(
                    'La dirección de '.$punto.' es ambigua. Precisala o mandá coordenadas.',
                    $origen,
                    $destino,
                );
            }

            if (! $ubicacion->estaEnCobertura()) {
                return self::rechazo(
                    'La dirección de '.$punto.' está fuera del área de cobertura.',
                    $origen,
                    $destino,
                );
            }

            // ⚠️ Las dos pueden estar en cobertura y aun así no ser la que se pidió. Es el
            // control que le da sentido a pedir la localidad; sin él el campo sería
            // decorativo. Comprobado contra la API real: «Av. Alberdi 500, Tafí Viejo»
            // vuelve ubicada en San Miguel de Tucumán con `partial_match` en false —Google
            // no avisa nada— y «San Martín 500, Banda del Río Salí» vuelve en La Cocha. Las
            // dos pasarían la validación de cobertura, porque la localidad que Google eligió
            // también está cubierta: se cotizaría un viaje y el cadete recibiría otra
            // dirección.
            $declarada = $datos['localidad_'.$punto] ?? null;

            if (is_string($declarada)
                && Ubicacion::normalizar($declarada) !== Ubicacion::normalizar($ubicacion->localidad)) {
                return self::rechazo(
                    'La dirección de '.$punto.' no existe en '.$declarada
                    .': se encontró en '.$ubicacion->localidad.'.',
                    $origen,
                    $destino,
                );
            }
        }

        $distancia = $this->mapas->distanciaEnMetros($origen, $destino);

        if ($distancia === null) {
            return self::rechazo('No se encontró una ruta entre las dos direcciones.', $origen, $destino);
        }

        $garantia = $datos['envia_dinero_monto'] ?? null;

        return [
            'error' => null,
            'distancia' => $distancia,
            'costo' => $this->cotizador->cotizar(
                $distancia,
                (bool) ($datos['retorno_origen'] ?? false),
                is_numeric($garantia) ? (string) $garantia : null,
            ),
            'origen' => $origen,
            'destino' => $destino,
        ];
    }

    /**
     * Un extremo del viaje, resuelto a un punto validado.
     *
     * ⚠️ Aun mandando coordenadas hay que geocodificar al revés: la cobertura se valida por
     * provincia y localidad, y eso no se deduce de un par de números.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws MapaNoDisponible
     */
    protected function resolver(array $datos, string $punto): ?Ubicacion
    {
        $lat = $datos[$punto.'_lat'] ?? null;
        $lng = $datos[$punto.'_lng'] ?? null;

        if (is_numeric($lat) && is_numeric($lng)) {
            return $this->mapas->geocodificarCoordenadas((float) $lat, (float) $lng);
        }

        return $this->mapas->geocodificar($this->direccionParaGeocodificar($datos, $punto));
    }

    /**
     * La dirección que se le consulta a Google: «calle altura, localidad, provincia».
     *
     * ⚠️ **La localidad se pide aparte a propósito.** Antes se recibía la dirección en un solo
     * campo de texto libre y se la mandaba tal cual a Google, que tenía que adivinar de qué
     * ciudad se trataba: «San Martín 500» existe en las cinco localidades cubiertas. La app de
     * clientes no tiene este problema porque no manda texto libre —la persona elige una
     * sugerencia del autocompletado y viaja un `place_id`, que ya es un lugar concreto—. Un
     * consumidor externo no tiene ese autocompletado, así que la desambiguación hay que
     * pedírsela.
     *
     * La provincia va en el texto aunque no se pida: no hay otra, el área de cobertura es
     * Tucumán entera, y acota más sin costar nada.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function direccionParaGeocodificar(array $datos, string $punto): string
    {
        return implode(', ', [
            (string) ($datos['direccion_'.$punto] ?? ''),
            (string) ($datos['localidad_'.$punto] ?? ''),
            (string) config('cobertura.provincia'),
        ]);
    }

    /**
     * Qué se guarda en `direccion` y `destino`.
     *
     * Si el consumidor mandó texto, se guarda su texto con la localidad pegada —«calle altura,
     * localidad»—, que es el mismo formato que arma la app de clientes y el que el cadete está
     * acostumbrado a leer. La provincia no se guarda: son todos pedidos de Tucumán y ocuparía
     * lugar en una columna de 255.
     *
     * Si mandó coordenadas, se usa la dirección que devolvió Google, porque un par de números
     * no le sirve a nadie en la calle.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function direccionParaGuardar(array $datos, string $punto, ?Ubicacion $ubicacion): string
    {
        $texto = $datos['direccion_'.$punto] ?? null;

        if (is_string($texto) && trim($texto) !== '') {
            return trim($texto).', '.(string) ($datos['localidad_'.$punto] ?? '');
        }

        if ($ubicacion?->direccionFormateada !== null) {
            return $ubicacion->direccionFormateada;
        }

        // Mandó coordenadas y Google no estaba: se guardan los números antes que dejar el
        // campo vacío. Es feo, pero es una dirección a la que se puede llegar; null no.
        return ($datos[$punto.'_lat'] ?? '').','.($datos[$punto.'_lng'] ?? '');
    }

    /**
     * @return array{error: string, costo: null, distancia: null, origen: Ubicacion|null, destino: Ubicacion|null}
     */
    protected static function rechazo(string $motivo, ?Ubicacion $origen = null, ?Ubicacion $destino = null): array
    {
        return [
            'error' => $motivo,
            'costo' => null,
            'distancia' => null,
            'origen' => $origen,
            'destino' => $destino,
        ];
    }
}
