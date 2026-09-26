<?php

namespace App\Etl\Tablas;

use App\Etl\Archivos;
use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * postulacion_documentos. **La única tabla donde un documento da más filas que
 * una**: cada postulación tiene hasta cuatro imágenes y cada una es una fila.
 * Por eso implementa `filas()` y no `fila()`.
 *
 * ⚠️ Son las imágenes más sensibles de las tres tablas: dos de las cuatro son el
 * DNI de la persona, de frente y de dorso. El disco es privado y la API sirve
 * los bytes; nada de esto puede estar en `public/` bajo ninguna circunstancia.
 *
 * ⚠️ Y es la única que hace los DOS trabajos, porque el origen tiene las dos
 * formas mezcladas: el sistema viejo ya convierte a archivo al contratar.
 *
 * | | Archivo ya en disco | base64 en la base |
 * |---|---|---|
 * | `dni_frente`, `dni_dorso`, `boleta_de_servicio` | 583 cada uno | 142 cada uno |
 * | `foto` | — | 725 |
 *
 * O sea 1.749 a copiar —1.416 MB— y 1.151 a decodificar. Los 1.749 **no están
 * en ningún backup de la base**: salen de un tar aparte del servidor viejo, y
 * hay que desempaquetar sólo esas rutas. El mismo tar trae 2.322 archivos más
 * —2.864 MB— que son DNI de 774 personas cuya postulación se borró: ésos no se
 * migran. Ver DATA-9.
 */
class PostulacionDocumentos extends Migrador
{
    /**
     * Los cuatro tipos, tal como se llaman en el origen y en el CHECK de la
     * columna `tipo`. Coinciden a propósito.
     */
    private const TIPOS = ['dni_frente', 'dni_dorso', 'boleta_de_servicio', 'foto'];

    private Archivos $archivos;

    /** @var array<string, int>|null */
    private ?array $postulaciones = null;

    public function coleccion(): string
    {
        return 'postulaciones_cadetes';
    }

    public function tabla(): string
    {
        return 'postulacion_documentos';
    }

    public function campos(): array
    {
        return [...self::TIPOS, 'created_at', 'updated_at'];
    }

    /**
     * Acá no hay una fila por documento: ver `filas()`.
     */
    public function fila(array $documento): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $documento
     * @return array<int, array<string, mixed>>
     */
    public function filas(array $documento): array
    {
        $this->archivos ??= new Archivos;
        $this->postulaciones ??= MapaDeIds::mapa('postulaciones');

        $legacy = Origen::id($documento['_id'] ?? null);

        $postulacion = ($legacy === null ? null : ($this->postulaciones[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Un documento pertenece a la postulación «'.($legacy ?? 'ninguna').'», que no está '
                .'en migracion_ids. ¿Se cargó postulaciones antes?'
            );

        $filas = [];

        foreach (self::TIPOS as $tipo) {
            $valor = $documento[$tipo] ?? null;

            if (! is_string($valor) || trim($valor) === '') {
                continue;
            }

            // `postulaciones/{oid}/{tipo}` en el origen, `postulacion-documentos/
            // {oid}/{tipo}` acá: la misma ruta cambiando el prefijo, así que los
            // 1.749 archivos se suben con un `sync` y sin renombrar nada.
            $destino = Archivos::POSTULACION_DOCUMENTOS."/{$legacy}/{$tipo}";

            $filas[] = [
                'postulacion_id' => $postulacion,
                'tipo' => $tipo,
                'ruta_archivo' => str_starts_with($valor, 'data:')
                    ? $this->archivos->desdeDataUri($valor, $destino)
                    : $this->archivos->copiando($valor, $destino),
                'created_at' => Origen::fecha($documento['created_at'] ?? null),
                'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            ];
        }

        return $filas;
    }

    /**
     * Cuántas filas tiene que salir, que no es cuántos documentos hay: son las
     * imágenes presentes en los cuatro campos, no las 725 postulaciones. Sin
     * esto la verificación del comando compararía 2.900 contra 725 y diría que
     * no coincide.
     */
    public function enOrigen(): int
    {
        $total = 0;

        foreach (self::TIPOS as $tipo) {
            $total += $this->origen->contar($this->coleccion(), [$tipo => ['$nin' => [null, '']]]);
        }

        return $total;
    }
}
