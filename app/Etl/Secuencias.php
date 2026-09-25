<?php

namespace App\Etl;

use Illuminate\Support\Facades\DB;

/**
 * Las dos secuencias de numeración del negocio, movidas al final de la carga.
 *
 * `pedidos.numero` y `clientes.numero` no son la clave primaria: son el número
 * con el que la oficina llama a un pedido o a un comercio. En el sistema viejo
 * los generaba un contador que tuvo una condición de carrera y dejó 11.294
 * números de pedido repetidos (DATA-1); en el nuevo los genera una SEQUENCE.
 *
 * Arrancan en 1, así que después de cargar hay que llevarlas al máximo que
 * quedó en la tabla. Si no, el primer pedido del sistema nuevo se numeraría 1 y
 * chocaría de frente con veinte años de historia.
 *
 * ⚠️ Esto va DESPUÉS de cargar, no antes: el ETL escribe los números tal como
 * venían, sin pedírselos a la secuencia.
 */
class Secuencias
{
    /** @var array<string, array{0: string, 1: string}> secuencia => [tabla, columna] */
    private const SECUENCIAS = [
        'pedidos_numero_seq' => ['pedidos', 'numero'],
        'clientes_numero_seq' => ['clientes', 'numero'],
    ];

    /**
     * Devuelve, por secuencia, el número en el que quedó.
     *
     * @return array<string, int>
     */
    public function mover(): array
    {
        $movidas = [];

        foreach (self::SECUENCIAS as $secuencia => [$tabla, $columna]) {
            $maximo = (int) DB::table($tabla)->max($columna);

            // El tercer argumento es `is_called`: en true, el próximo nextval
            // devuelve máximo + 1. Con la tabla vacía se deja en 1 sin llamar,
            // para que el primero sea el 1 y no el 2.
            DB::select('select setval(?, ?, ?)', [$secuencia, max($maximo, 1), $maximo > 0]);

            $movidas[$secuencia] = $maximo;
        }

        return $movidas;
    }
}
