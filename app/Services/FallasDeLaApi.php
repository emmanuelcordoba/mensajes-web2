<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que falló, resumido: por código, por ruta, por móvil y por día.
 *
 * Lee lo que Telescope ya guarda —ver `App\Providers\TelescopeServiceProvider`, que
 * explica por qué sólo guarda lo que falla— y lo cuenta. Telescope de por sí muestra un
 * pedido a la vez, que es lo que sirve para entender UNO; esto contesta las otras
 * preguntas: cuántos, de qué código, en qué ruta y de qué móvil.
 *
 * ⚠️ **Es la pregunta que el monitoreo de Laravel Cloud no contesta.** Esa pantalla dice
 * «187 respuestas 4XX en /api/actualizarubicacion» y ahí se termina. Si son 187 cadetes
 * con un problema cada uno o un solo teléfono con el GPS enloquecido se arregla de dos
 * maneras distintas, y esa diferencia está acá.
 *
 * ⚠️ Las consultas son de PostgreSQL —`content->>'...'`, `date_trunc`— y no se pretende
 * que sean de otra cosa: la base es la que define `ESQUEMA.sql`.
 *
 * ⚠️ Nada de esto existe antes de instalar Telescope, y la tabla arranca vacía: lo que
 * falló ayer no está. Empieza a contar desde que se despliega.
 *
 * @phpstan-type PorCodigo array{codigo: int, cuantas: int}
 * @phpstan-type PorRuta array{ruta: string, codigo: int, cuantas: int}
 * @phpstan-type PorMovil array{movil: int, cuantas: int}
 * @phpstan-type PorDia array{dia: string, cuantas: int}
 * @phpstan-type Excepcion array{clase: string, mensaje: string, cuantas: int, uuid: string}
 * @phpstan-type Falla array{uuid: string, ruta: string, metodo: string, codigo: int, movil: int|null, cuando: string}
 */
class FallasDeLaApi
{
    /** Los días que se pueden mirar. El último es el que dura la poda. */
    public const DIAS = [1, 7, 30];

    /**
     * Cómo se entra adentro de lo que guardó Telescope.
     *
     * ⚠️ **El `::jsonb` hace falta.** La columna `content` es de texto, no de JSON —así la
     * crea la migración de Telescope—, y sin el casteo PostgreSQL contesta «operator does
     * not exist: text ->> unknown». Primero filtra el `type`, que sí está indexado, así
     * que el casteo corre sobre las peticiones y no sobre la tabla entera.
     */
    private const DENTRO = 'content::jsonb';

    /**
     * El código de respuesta, que vive adentro de ese JSON y sale como texto.
     *
     * ⚠️ Es una constante y no un método porque PHPStan sólo deja meter texto literal en
     * un `selectRaw`, y con razón: es el lugar por donde entra una inyección.
     */
    private const CODIGO = '('.self::DENTRO."->>'response_status')::int";

    /** Si Telescope está recolectando. Apagado, todo esto cuenta cero y hay que decirlo. */
    public function estaPrendido(): bool
    {
        return (bool) config('telescope.enabled');
    }

    /** Cuántas fallas en total, que es contra lo que se leen los porcentajes. */
    public function total(int $dias): int
    {
        return $this->fallidas($dias)->count();
    }

    /**
     * Por código de respuesta: el primer corte, y el que decide para dónde mirar.
     *
     * Un 401 es la app mandando con un token muerto, un 422 es el cuerpo, un 429 es el
     * tope de pedidos y un 500 es nuestro.
     *
     * @return Collection<int, PorCodigo>
     */
    public function porCodigo(int $dias): Collection
    {
        return $this->fallidas($dias)
            ->selectRaw(self::CODIGO.' as codigo, count(*) as cuantas')
            ->groupBy('codigo')
            ->orderByDesc('cuantas')
            ->get()
            ->map(fn (object $fila): array => [
                'codigo' => (int) $fila->codigo,
                'cuantas' => (int) $fila->cuantas,
            ]);
    }

    /**
     * Por ruta y código, que es lo mismo que muestra Cloud pero guardado más de un día.
     *
     * @return Collection<int, PorRuta>
     */
    public function porRuta(int $dias): Collection
    {
        return $this->fallidas($dias)
            ->selectRaw(self::DENTRO."->>'uri' as ruta, ".self::CODIGO.' as codigo, count(*) as cuantas')
            ->groupBy('ruta', 'codigo')
            ->orderByDesc('cuantas')
            ->limit(20)
            ->get()
            ->map(fn (object $fila): array => [
                'ruta' => (string) $fila->ruta,
                'codigo' => (int) $fila->codigo,
                'cuantas' => (int) $fila->cuantas,
            ]);
    }

    /**
     * Por móvil.
     *
     * ⚠️ **Es la pregunta que motivó todo esto.** Sale de la etiqueta que pone
     * `TelescopeServiceProvider`, así que sólo aparecen las fallas de alguien autenticado:
     * un 401 no tiene móvil, porque justamente no se supo quién era.
     *
     * @return Collection<int, PorMovil>
     */
    public function porMovil(int $dias): Collection
    {
        return $this->fallidas($dias)
            ->join('telescope_entries_tags as etiquetas', 'etiquetas.entry_uuid', '=', 'telescope_entries.uuid')
            ->where('etiquetas.tag', 'like', 'movil:%')
            ->selectRaw('substring(etiquetas.tag from 7) as movil, count(*) as cuantas')
            ->groupBy('movil')
            ->orderByDesc('cuantas')
            ->limit(10)
            ->get()
            ->map(fn (object $fila): array => [
                'movil' => (int) $fila->movil,
                'cuantas' => (int) $fila->cuantas,
            ]);
    }

    /**
     * Por día, para ver si lo de hoy es lo normal o es un día raro.
     *
     * @return Collection<int, PorDia>
     */
    public function porDia(int $dias): Collection
    {
        return $this->fallidas($dias)
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as dia, count(*) as cuantas")
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->map(fn (object $fila): array => [
                'dia' => (string) $fila->dia,
                'cuantas' => (int) $fila->cuantas,
            ]);
    }

    /**
     * Las excepciones, agrupadas por clase.
     *
     * Son las que explican un 500. El `uuid` es el del último caso, para poder abrirlo en
     * Telescope y ver la traza entera.
     *
     * @return Collection<int, Excepcion>
     */
    public function excepciones(int $dias): Collection
    {
        $filas = DB::table('telescope_entries')
            ->where('type', 'exception')
            ->where('created_at', '>=', $this->desde($dias))
            ->selectRaw(self::DENTRO."->>'class' as clase, max(".self::DENTRO."->>'message') as mensaje, "
                .'count(*) as cuantas, max(uuid::text) as uuid')
            ->groupBy('clase')
            ->orderByDesc('cuantas')
            ->limit(10)
            ->get();

        return $filas->map(fn (object $fila): array => [
            'clase' => (string) $fila->clase,
            'mensaje' => (string) $fila->mensaje,
            'cuantas' => (int) $fila->cuantas,
            'uuid' => (string) $fila->uuid,
        ]);
    }

    /**
     * Las últimas, una por una, para abrir alguna y mirarla en Telescope.
     *
     * @return Collection<int, Falla>
     */
    public function ultimas(int $dias, int $cuantas = 15): Collection
    {
        $filas = $this->fallidas($dias)
            ->selectRaw('uuid, '.self::DENTRO."->>'uri' as ruta, ".self::DENTRO."->>'method' as metodo, "
                .self::CODIGO.' as codigo, created_at')
            ->orderByDesc('created_at')
            ->limit($cuantas)
            ->get();

        $moviles = $this->movilesDe(array_values($filas->pluck('uuid')->all()));

        return $filas->map(fn (object $fila): array => [
            'uuid' => (string) $fila->uuid,
            'ruta' => (string) $fila->ruta,
            'metodo' => (string) $fila->metodo,
            'codigo' => (int) $fila->codigo,
            'movil' => $moviles[(string) $fila->uuid] ?? null,
            'cuando' => Carbon::parse($fila->created_at)->format('d/m H:i:s'),
        ]);
    }

    /**
     * El móvil de cada uno, en una sola consulta.
     *
     * @param  list<mixed>  $uuids
     * @return array<string, int>
     */
    protected function movilesDe(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return DB::table('telescope_entries_tags')
            ->whereIn('entry_uuid', $uuids)
            ->where('tag', 'like', 'movil:%')
            ->pluck('tag', 'entry_uuid')
            ->map(fn (string $etiqueta): int => (int) substr($etiqueta, 6))
            ->all();
    }

    /**
     * Las peticiones que no salieron bien.
     *
     * ⚠️ El `>= 400` hace falta aunque el filtro de Telescope guarde sólo lo que falla:
     * en local se guarda todo, que es donde se prueba esta pantalla.
     */
    protected function fallidas(int $dias): Builder
    {
        return DB::table('telescope_entries')
            ->where('type', 'request')
            ->whereRaw(self::CODIGO.' >= 400')
            ->where('created_at', '>=', $this->desde($dias));
    }

    protected function desde(int $dias): Carbon
    {
        return Carbon::now()->subDays($dias);
    }
}
