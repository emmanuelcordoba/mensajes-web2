<?php

namespace App\Models;

use App\Models\Concerns\LeeLoQueEscribeLaBase;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un cliente: el comercio o la persona que pide un envío.
 *
 * ## Dos nombres, y un tercero que los resuelve
 *
 * `nombre` lo escribe el panel; `nombres` y `apellidos` los escribe la app. No se
 * pisan nunca, y **`nombre_mostrado` es lo que lee todo el sistema**. Es una
 * columna generada en la base, no un accessor: así ningún camino de escritura
 * puede dejar un cliente sin nombre ni con espacios de más, sin depender de que
 * el modelo se acuerde. Eso es lo que DATA-8 vino a arreglar —un solo espacio
 * doble alcanzaba para que el panel no vinculara el pedido a su cliente, en 138
 * clientes—.
 *
 * ⚠️ Al crear un cliente, `nombre_mostrado` NO queda cargado en el modelo: lo
 * calcula PostgreSQL al insertar, y Eloquent no lo vuelve a leer. Hay que
 * `refresh()` o volver a buscarlo. Hay una prueba que lo fija, para que nadie se
 * sorprenda.
 *
 * ## El número
 *
 * Sale de `clientes_numero_seq` por DEFAULT, así que no se calcula en PHP. En el
 * sistema viejo se calculaba mirando toda la colección (PERF-1), y un contador
 * habría sido un error porque nada garantizaba unicidad; acá la columna es UNIQUE
 * y la secuencia es atómica.
 *
 * ⚠️ El panel deja que el empleado escriba el número a mano, y en ese caso la
 * secuencia no avanza: un `nextval` posterior puede chocar con un número puesto a
 * mano. El UNIQUE lo atrapa —no se corrompe nada— pero el alta falla, así que ese
 * camino necesita reintentar o dejar de ofrecer el campo. Es una decisión del
 * panel, no del modelo.
 *
 * @property int $id
 * @property int $numero
 * @property string|null $nombre
 * @property string|null $nombres
 * @property string|null $apellidos
 * @property string $nombre_mostrado
 * @property string|null $nombre_empleado
 * @property string $direccion
 * @property string $telefono
 * @property string|null $plataforma
 * @property string|null $fcm_token
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, Pedido> $pedidos
 */
#[Fillable([
    'numero',
    'nombre',
    'nombres',
    'apellidos',
    'nombre_empleado',
    'direccion',
    'telefono',
    'plataforma',
    'fcm_token',
    'user_id',
])]
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory, LeeLoQueEscribeLaBase, SoftDeletes;

    /** Los clientes que vinieron de la app se marcan así; los del panel, NULL. */
    public const PLATAFORMA_APP = 'app';

    /**
     * Las dos que escribe PostgreSQL: `numero` sale de `clientes_numero_seq` por
     * DEFAULT y `nombre_mostrado` es una columna generada. Sin esto quedan en NULL
     * en el modelo recién creado. Ver el trait.
     *
     * ⚠️ `nombre_mostrado` no está en `Fillable`, así que no se puede asignar en
     * masa. Un `forceFill` o un `setAttribute` sí llegan —Eloquent no lo impide— y
     * ahí lo rechaza PostgreSQL, porque una columna generada no se escribe.
     *
     * @return list<string>
     */
    protected function loQueEscribeLaBase(): array
    {
        return ['numero', 'nombre_mostrado'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Pedido, $this> */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * Los cuatro campos de nombre se guardan sin espacios de más.
     *
     * `nombre_mostrado` ya los normaliza para lo que el sistema lee, pero eso no
     * limpia lo que queda guardado en la columna de origen. Un `nombre` con un
     * espacio doble adentro no rompe nada hoy, y es basura que alguien va a ver.
     */
    /** @return Attribute<string|null, string|null> */
    protected function nombre(): Attribute
    {
        return Attribute::make(set: self::sinEspaciosDeMas(...));
    }

    /** @return Attribute<string|null, string|null> */
    protected function nombres(): Attribute
    {
        return Attribute::make(set: self::sinEspaciosDeMas(...));
    }

    /** @return Attribute<string|null, string|null> */
    protected function apellidos(): Attribute
    {
        return Attribute::make(set: self::sinEspaciosDeMas(...));
    }

    /** @return Attribute<string|null, string|null> */
    protected function nombreEmpleado(): Attribute
    {
        return Attribute::make(set: self::sinEspaciosDeMas(...));
    }

    /** Un solo espacio entre palabras, y ninguno en las puntas. */
    public static function sinEspaciosDeMas(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }

    /** El nombre de la persona que usa la app, que no es el del cliente. */
    public function nombreDePersona(): string
    {
        return (string) self::sinEspaciosDeMas($this->nombres.' '.$this->apellidos);
    }

    /**
     * Carga los nombres de la persona que usa la app.
     *
     * El nombre del cliente se rearma con los de la persona **sólo si todavía era
     * ése**. Si el panel le puso otro —porque es un comercio, o porque lo
     * corrigió— se respeta: hasta DATA-8 la app lo pisaba siempre, y así se
     * perdieron 51 nombres de comercio que no se pueden recuperar de los backups.
     */
    public function ponerNombresDePersona(string $nombres, string $apellidos): void
    {
        $eraElDeLaPersona = self::claveDeNombre($this->nombre) === self::claveDeNombre($this->nombreDePersona());

        $this->nombres = $nombres;
        $this->apellidos = $apellidos;

        if ($eraElDeLaPersona) {
            $this->nombre = $this->nombreDePersona();
        }
    }

    /**
     * La forma de un nombre que se usa para compararlo: minúsculas, sin acentos,
     * con la ñ aparte y sin espacios de más.
     *
     * No usa la extensión intl, que no hay por qué suponer instalada.
     */
    public static function claveDeNombre(?string $nombre): ?string
    {
        if ($nombre === null) {
            return null;
        }

        return strtr(mb_strtolower((string) self::sinEspaciosDeMas($nombre)), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ç' => 'c',
        ]);
    }

    /**
     * El cliente con ese nombre, si hay exactamente uno.
     *
     * Si hay varios devuelve null, porque no hay forma de saber cuál es: el panel
     * entonces deja el pedido sin vincular en vez de adivinar (DATA-8).
     *
     * ⚠️ **Compara sin mayúsculas pero SÍ con acentos**, y el sistema viejo
     * comparaba sin ninguno de los dos —usaba la collation de MongoDB—. O sea que
     * «Peña» y «Pena» eran el mismo cliente allá y son dos acá. Igualarlo necesita
     * la extensión `unaccent` y un índice funcional, porque
     * `idx_clientes_nombre_mostrado` es un btree común y una comparación con
     * función no lo usa. Queda anotado y sin resolver: es una decisión de
     * esquema, y hay 11.057 clientes contra los que medir qué cambia.
     */
    public static function unicoConNombre(?string $nombre): ?self
    {
        $nombre = self::sinEspaciosDeMas($nombre);

        if ($nombre === null || $nombre === '') {
            return null;
        }

        $encontrados = self::query()
            ->whereRaw('lower(nombre_mostrado) = lower(?)', [$nombre])
            ->limit(2)
            ->get();

        return $encontrados->count() === 1 ? $encontrados->first() : null;
    }
}
