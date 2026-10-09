<?php

namespace App\Services;

use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use Illuminate\Support\Facades\DB;

/**
 * Alguien se postula como cadete desde la app: la fila y sus cuatro imágenes.
 *
 * ⚠️ **En el sistema viejo las cuatro imágenes son cuatro columnas con el base64 adentro**, y
 * al contratar se escriben a disco y la columna pasa a tener una ruta: la misma columna
 * guardaba dos cosas distintas según el momento, y el panel comprobaba cuál era con
 * `strpos($valor, 'data:') === 0`. Acá son filas de `postulacion_documentos` y siempre son
 * una ruta (PERF-2). Son 725 postulaciones × 4 imágenes, con una mediana de 656 KB cada una:
 * guardarlas en el documento significaba arrastrarlas en cada consulta que leyera una
 * postulación.
 *
 * ⚠️ **Los archivos se escriben DESPUÉS de la transacción**, igual que la foto de perfil.
 * Escribir un archivo adentro de una transacción no se deshace si el COMMIT falla: quedarían
 * cuatro archivos huérfanos en el bucket, y dos de ellos son el DNI de una persona (DATA-9).
 * Al revés, lo peor que pasa es que una imagen no entre y la fila la reclame como faltante,
 * que es justo lo que `DocumentosDePostulacion::faltanParaContratar()` sabe decir.
 */
class PostulacionDesdeLaApp
{
    /**
     * Los campos de la fila. Las imágenes van aparte y `fcm_token` no va a ningún lado.
     *
     * @var list<string>
     */
    public const DIRECTOS = [
        'nombres',
        'apellidos',
        'direccion',
        'telefono',
        'tipo_vehiculo',
        'fecha_nacimiento',
        'email',
        'recomendado_por',
        'pregunta_experiencia_app',
        'pregunta_tiene_celular',
        'pregunta_equipo_en_condiciones',
        'pregunta_tiene_datos',
    ];

    /**
     * @param  array<string, mixed>  $datos  Ya validados por `PostularseRequest`.
     */
    public function guardar(array $datos): Postulacion
    {
        $postulacion = DB::transaction(function () use ($datos): Postulacion {
            $fila = [];

            foreach (self::DIRECTOS as $campo) {
                $fila[$campo] = $datos[$campo] ?? null;
            }

            // Entero, y no el texto que llegó: la columna es INTEGER. En el sistema viejo
            // convivían el mismo documento como texto y como número (PANEL-15, DATA-4).
            $fila['dni'] = (int) $datos['dni'];

            return Postulacion::query()->create($fila);
        });

        foreach (PostulacionDocumento::TIPOS as $tipo) {
            DocumentosDePostulacion::guardarDataUrl($postulacion, $tipo, (string) $datos[$tipo]);
        }

        return $postulacion;
    }
}
