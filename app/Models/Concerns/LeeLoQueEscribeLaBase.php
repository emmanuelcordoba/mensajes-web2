<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Trae, después de insertar, las columnas que escribe PostgreSQL y no PHP.
 *
 * Eloquent sólo lee de vuelta la clave primaria. Todo lo demás que ponga la base
 * queda en NULL en el modelo recién creado, aunque en la tabla tenga su valor, y
 * son tres casos:
 *
 * - Una **secuencia**: `DEFAULT nextval(...)`, como `pedidos.numero`.
 * - Una **columna generada**, como `clientes.nombre_mostrado`.
 * - Un **DEFAULT cualquiera**, como `mensajes.leido_web DEFAULT FALSE`. Éste es el
 *   más fácil de pasar por alto: el modelo devuelve NULL donde la tabla dice
 *   `false`, así que una respuesta de la API serializa null y una comparación con
 *   `=== false` falla.
 *
 * Y en este esquema eso no es un detalle: `numero` es **el número de pedido**, lo
 * que la oficina dice en voz alta y lo que el comprobante imprime. Un
 * `Pedido::create()` que devuelve `numero` en NULL es una trampa esperando a que
 * la API devuelva un pedido sin número.
 *
 * Cuesta un SELECT por inserción. Se puede pagar: son unos pocos miles de altas
 * por día, no un millón.
 */
trait LeeLoQueEscribeLaBase
{
    /**
     * Las columnas que escribe la base.
     *
     * @return list<string>
     */
    abstract protected function loQueEscribeLaBase(): array;

    protected static function bootLeeLoQueEscribeLaBase(): void
    {
        static::created(static function (Model $modelo): void {
            /** @var static $modelo */
            $modelo->leerLoQueEscribeLaBase();
        });
    }

    public function leerLoQueEscribeLaBase(): void
    {
        $columnas = $this->loQueEscribeLaBase();

        if ($columnas === []) {
            return;
        }

        $fila = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->first($columnas);

        if ($fila === null) {
            return;
        }

        foreach ((array) $fila as $columna => $valor) {
            $this->setAttribute($columna, $valor);
        }

        // Sin esto el modelo las vería como sucias y el próximo save() intentaría
        // escribirlas. Sobre una columna generada, PostgreSQL lo rechaza.
        $this->syncOriginalAttributes($columnas);
    }
}
