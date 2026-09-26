<?php

namespace App\Models;

use Database\Factories\ConfiguracionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Los parámetros que la oficina edita desde el panel: costos, demoras, garantía.
 *
 * Son ocho filas. `valor` es TEXT y `tipo` dice cómo leerlo, porque la misma tabla
 * guarda «3000» y «30 minutos».
 *
 * ⚠️ Un `CHECK` de la columna exige que los de tipo `numero` sean dígitos con un
 * punto decimal opcional: nada de notación científica, nada de negativos, y nada
 * de un «250.0» que no es lo que alguien escribió. Eso no lo validaba el sistema
 * viejo, donde `valor` podía ser cualquier cosa.
 *
 * ⚠️ **Esta tabla no tiene `deleted_at`**, a diferencia del modelo viejo, que usaba
 * `SoftDeletes` sobre una tabla que no lo soportaba. Una configuración no se borra:
 * el código la busca por nombre y espera encontrarla.
 *
 * @property int $id
 * @property string $nombre
 * @property string $titulo
 * @property string|null $descripcion
 * @property string $tipo
 * @property string|null $valor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'titulo', 'descripcion', 'tipo', 'valor'])]
class Configuracion extends Model
{
    /** @use HasFactory<ConfiguracionFactory> */
    use HasFactory;

    public const TIPO_NUMERO = 'numero';

    public const TIPO_TEXTO = 'texto';

    /** Lo que ofrece el panel para «tiempo de demora». */
    public const TIEMPOS_DE_DEMORA = [
        '15 minutos',
        '30 minutos',
        '45 minutos',
        '60 minutos',
    ];

    protected $table = 'configuraciones';

    /**
     * El valor de una configuración, ya convertido según su tipo.
     *
     * ⚠️ **Frena si no existe.** El modelo viejo hacía `$config->tipo` sobre el
     * resultado de un `first()` que podía ser null, así que una configuración
     * faltante daba «Attempt to read property on null» en medio de una cotización,
     * sin decir cuál faltaba. Acá el error nombra la clave.
     *
     * ⚠️ Los de tipo `numero` salen como `float`, como en el sistema viejo, y eso
     * incluye los costos. Para dinero no es lo ideal —ver el `casts()` de
     * `Pedido`, donde los montos se dejan como decimal a propósito—, pero
     * cambiarlo cambia la aritmética de las cotizaciones, y eso es una decisión
     * con COT-1 de por medio, no un detalle de tipos.
     */
    public static function valor(string $nombre): float|string|null
    {
        $configuracion = self::query()->where('nombre', $nombre)->first();

        if ($configuracion === null) {
            throw new RuntimeException("No existe la configuración «{$nombre}».");
        }

        if ($configuracion->tipo === self::TIPO_NUMERO) {
            return (float) $configuracion->valor;
        }

        return $configuracion->valor;
    }

    /** El número de una configuración numérica, que es el uso real de `valor()`. */
    public static function numero(string $nombre): float
    {
        $valor = self::valor($nombre);

        if (! is_float($valor)) {
            throw new RuntimeException(
                "La configuración «{$nombre}» es de tipo texto, y se pidió como número."
            );
        }

        return $valor;
    }

    /**
     * El valor de una configuración numérica **como cadena**, sin convertir.
     *
     * Es lo que hace falta para operar con dinero: `numero()` devuelve un `float`
     * —como el sistema viejo— y un float no es donde se hacen cuentas de plata.
     *
     * Exige que sea de tipo `numero`, y con eso el `CHECK` de la columna garantiza
     * que sean dígitos con un punto decimal opcional: la cadena se puede pasar a
     * bcmath tal cual.
     *
     * @return numeric-string
     */
    public static function numeroCrudo(string $nombre): string
    {
        $configuracion = self::query()->where('nombre', $nombre)->first();

        if ($configuracion === null) {
            throw new RuntimeException("No existe la configuración «{$nombre}».");
        }

        if (! $configuracion->esNumerica() || ! is_numeric($configuracion->valor)) {
            throw new RuntimeException(
                "La configuración «{$nombre}» no es un número, y se pidió para una cuenta."
            );
        }

        return $configuracion->valor;
    }

    public function esNumerica(): bool
    {
        return $this->tipo === self::TIPO_NUMERO;
    }
}
