<?php

namespace App\Http\Resources\Api\ClienteApp\V1;

use App\Models\PublicidadApp;
use App\Services\ImagenesDeLaApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una imagen del carrusel de la pantalla de inicio.
 *
 * ⚠️ **`img_base64` ya no lleva base64: lleva una URL firmada.** El nombre miente y se queda,
 * porque la plantilla de la app hace `<img [src]="slide.img_base64">` y ese literal está en
 * los teléfonos. Un `<img src>` acepta las dos cosas, así que lo único que cambia es cuánto
 * viaja.
 *
 * Y cambia bastante. Medido sobre las 4 publicidades visibles: **692 KB en disco, ~948 KB
 * como data URI** —una sola pesa 537 KB—, y eso viajaba en CADA carga de la pantalla de
 * inicio, que es la primera que el cliente abre. Con el enlace son unos cientos de bytes y
 * la imagen la cachea la vista web. Es la misma decisión que la foto del cadete, donde el
 * base64 en la columna eran 1.129 MB.
 *
 * ⚠️ El enlace vale 30 minutos —`ImagenesDeLaApp::MINUTOS_DE_LA_URL`—. La pantalla se vuelve
 * a pedir cada vez que se entra, así que alcanza; si algún día el carrusel apareciera roto,
 * ésa es la perilla.
 *
 * ⚠️ `url` es el destino de la publicidad —un perfil de Facebook, en las cuatro de hoy— y no
 * se emite: la plantilla sólo dibuja la imagen, no la hace tocable. Buscado en toda la
 * historia del repositorio de la app, nunca se leyó.
 *
 * @mixin PublicidadApp
 */
class PublicidadDeLaApp extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'img_base64' => ImagenesDeLaApp::url($this->imagen?->ruta_archivo),
        ];
    }
}
