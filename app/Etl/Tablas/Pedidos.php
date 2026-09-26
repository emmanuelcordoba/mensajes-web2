<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * pedidos. La tabla grande: 959.973 documentos. Va después de clientes y
 * cadetes, que referencia.
 *
 * ⚠️ `numero` NO es UNIQUE, y es a propósito: DATA-1 dejó 11.294 números
 * repetidos en 23.303 pedidos, producto de una condición de carrera que el
 * contador atómico ya corrigió. El esquema no puede exigir algo que los datos
 * históricos no cumplen, así que la unicidad la sostiene la secuencia de aquí en
 * adelante, no una restricción sobre el pasado.
 *
 * ⚠️ Las dos formas de identificar al destinatario conviven a propósito: los
 * pedidos cargados desde el panel no vinculan un Cliente y guardan el nombre
 * suelto en `nombre_cliente`. 464.071 tienen `cliente_id` y 504.353
 * `nombre_cliente`; no es un error a normalizar.
 *
 * `gastronomia` y `retorno_origen` casi no existen en el origen —72 y 229.651
 * documentos los tienen— y la columna es NOT NULL DEFAULT FALSE: ausente entra
 * como `false`, que es lo que el sistema viejo interpreta.
 *
 * Los montos tienen valores llamativos —un `valor` negativo, dos por encima del
 * millón, 86 `garantia` sobre el millón— pero **ninguno rompe NUMERIC(12,2)** y
 * una garantía alta puede ser legítima. Se migran como están; no son de los que
 * frenan.
 *
 * ⚠️ `anotaIds()` es true porque `logs_estados_pedidos` y
 * `cobranza_saldo_movimientos` los referencian, pero **nadie puede llamar a
 * `MapaDeIds::mapa('pedidos')`**: es un millón de entradas en memoria. Los hijos
 * resuelven su clave foránea con un JOIN contra `migracion_ids`.
 */
class Pedidos extends Migrador
{
    /**
     * Cada clave foránea, y la colección de la que sale.
     *
     * @var array<string, string>
     */
    private const REFERENCIAS = [
        'cliente_id' => 'clientes',
        'cadete_id' => 'cadetes',
        'user_id' => 'users',
    ];

    /** @var array<string, array<string, int>> */
    private array $mapas = [];

    public function coleccion(): string
    {
        return 'pedidos';
    }

    public function tabla(): string
    {
        return 'pedidos';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return [
            'numero', 'direccion', 'destino', 'detalle', 'responsable', 'telefono',
            'valor', 'garantia', 'tipo_paquete', 'peso_paquete', 'valor_declarado',
            'plataforma_origen', 'estado', 'gastronomia', 'retorno_origen',
            'cliente_id', 'nombre_cliente', 'cadete_id', 'user_id',
            'created_at', 'updated_at', 'deleted_at',
        ];
    }

    public function fila(array $documento): ?array
    {
        return [
            'numero' => isset($documento['numero']) ? (int) $documento['numero'] : null,
            'direccion' => self::texto($documento['direccion'] ?? null),
            'destino' => self::texto($documento['destino'] ?? null),
            'detalle' => self::texto($documento['detalle'] ?? null),
            'responsable' => self::texto($documento['responsable'] ?? null),
            'telefono' => self::texto($documento['telefono'] ?? null),
            'valor' => self::numero($documento['valor'] ?? null),
            'garantia' => self::numero($documento['garantia'] ?? null),
            'tipo_paquete' => self::texto($documento['tipo_paquete'] ?? null),
            'peso_paquete' => self::texto($documento['peso_paquete'] ?? null),
            'valor_declarado' => self::numero($documento['valor_declarado'] ?? null),
            'plataforma_origen' => self::aplastar((string) ($documento['plataforma_origen'] ?? '')),
            'estado' => self::aplastar((string) ($documento['estado'] ?? '')),
            'gastronomia' => (bool) ($documento['gastronomia'] ?? false),
            'retorno_origen' => (bool) ($documento['retorno_origen'] ?? false),
            'cliente_id' => $this->referencia($documento, 'cliente_id'),
            'nombre_cliente' => self::texto($documento['nombre_cliente'] ?? null),
            'cadete_id' => $this->referencia($documento, 'cadete_id'),
            'user_id' => $this->referencia($documento, 'user_id'),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }

    /**
     * Las claves foráneas se comprueban **contra el origen**, no contra
     * `migracion_ids`: en una carga desde cero los mapas están vacíos cuando
     * esto corre, y todo parecería huérfano. Que el cliente exista en MongoDB
     * es la pregunta de verdad; que esté en el mapa es consecuencia.
     *
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $problemas = [];

        foreach (self::REFERENCIAS as $campo => $coleccion) {
            $existentes = [];

            foreach ($this->origen->documentos($coleccion, [], ['_id']) as $documento) {
                $id = Origen::id($documento['_id'] ?? null);

                if ($id !== null) {
                    $existentes[$id] = true;
                }
            }

            $huerfanos = 0;
            $ilegibles = 0;

            foreach ($this->origen->distintos($this->coleccion(), $campo) as $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }

                $id = Origen::id($valor);

                if ($id === null) {
                    $ilegibles++;
                } elseif (! isset($existentes[$id])) {
                    $huerfanos++;
                }
            }

            if ($huerfanos > 0) {
                $problemas[] = sprintf(
                    '%d valores de `%s` apuntan a un documento de `%s` que no existe. La clave '
                    .'foránea los rechaza: se anulan con `datos:limpiar-referencias-huerfanas` '
                    .'(DATA-6).',
                    $huerfanos,
                    $campo,
                    $coleccion,
                );
            }

            if ($ilegibles > 0) {
                $problemas[] = sprintf(
                    '%d valores de `%s` no tienen forma de ObjectId. El ETL no adivina a quién '
                    .'apuntan.',
                    $ilegibles,
                    $campo,
                );
            }
        }

        return $problemas;
    }

    /**
     * El id nuevo de una referencia. Ausente es válido —más de la mitad de los
     * pedidos no tienen cliente—, pero presente y sin resolver, no.
     *
     * @param  array<string, mixed>  $documento
     */
    private function referencia(array $documento, string $campo): ?int
    {
        $valor = $documento[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return null;
        }

        $legacy = Origen::id($valor);

        if ($legacy === null) {
            throw new RuntimeException("Un pedido tiene `{$campo}` sin forma de ObjectId.");
        }

        $coleccion = self::REFERENCIAS[$campo];
        $this->mapas[$coleccion] ??= MapaDeIds::mapa($coleccion);

        return $this->mapas[$coleccion][$legacy] ?? throw new RuntimeException(
            "Un pedido apunta a `{$campo}` = «{$legacy}», que no está en migracion_ids. "
            ."¿Se cargó {$coleccion} antes?"
        );
    }

    private static function numero(mixed $valor): ?float
    {
        return is_int($valor) || is_float($valor) ? (float) $valor : null;
    }

    private static function aplastar(string $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = self::aplastar($valor);

        return $texto === '' ? null : $texto;
    }
}
