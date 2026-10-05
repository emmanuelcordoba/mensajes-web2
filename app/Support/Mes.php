<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * El mes que está mirando la pantalla de estadísticas.
 *
 * ⚠️ **EXISTE POR UN BUG DEL SISTEMA VIEJO.** Allá el valor del `<input type="month">` se
 * lee con `Carbon::createFromFormat('Y-m', $mes)`, y a un formato que no nombra el día PHP
 * le completa **el día de hoy**. Así, el 31 de marzo, pedir febrero cae el 3 de marzo
 * —febrero no tiene 31 días— y la pantalla muestra marzo sin avisar nada. Medido:
 *
 *     hoy 2026-03-31, pido 2026-02  ->  cae en 2026-03-03, firstOfMonth 2026-03-01
 *     hoy 2026-03-30, pido 2026-02  ->  cae en 2026-03-02, firstOfMonth 2026-03-01
 *     hoy 2026-03-29, pido 2026-02  ->  cae en 2026-03-01, firstOfMonth 2026-03-01
 *     hoy 2026-03-15, pido 2026-02  ->  cae en 2026-02-15, firstOfMonth 2026-02-01  (bien)
 *     hoy 2026-05-31, pido 2026-04  ->  cae en 2026-05-01, firstOfMonth 2026-05-01
 *
 * Son tres días al año en que febrero se ve como marzo y uno por cada mes de 30, y caen justo
 * a fin de mes, que es cuando uno mira el mes que cerró.
 *
 * ⚠️ **Y no revienta con lo que venga en la URL.** `?mes=cualquiera` tira
 * `InvalidFormatException` y la pantalla vieja da 500. `?mes=2026-13` es peor, porque no
 * falla: muestra enero de 2027 como si fuera un mes que alguien pidió. Acá lo que no es un
 * mes es el mes actual, y el título dice cuál se está mirando.
 */
final class Mes
{
    /** El formato del `<input type="month">`, que es el que viaja en la URL. */
    public const FORMATO = 'Y-m';

    /**
     * Los meses en castellano, escritos y no traducidos.
     *
     * Por lo mismo que `Momento::hace()`: `isoFormat('MMMM')` devuelve «September» o
     * «septiembre» según cómo esté cargado el locale de Carbon en el proceso, y son doce
     * palabras que no van a cambiar.
     */
    private const NOMBRES = [
        1 => 'enero',
        'febrero',
        'marzo',
        'abril',
        'mayo',
        'junio',
        'julio',
        'agosto',
        'septiembre',
        'octubre',
        'noviembre',
        'diciembre',
    ];

    private function __construct(private readonly CarbonImmutable $primerDia) {}

    public static function actual(): self
    {
        return new self(CarbonImmutable::now()->startOfMonth());
    }

    /**
     * El mes que pide la URL, o el actual si lo que pide no es un mes.
     *
     * Del futuro no hay estadísticas, así que un mes posterior al actual se trae al actual.
     * El atributo `max` del input ya lo evita en el navegador, pero la URL se escribe a mano.
     */
    public static function pedido(?string $texto): self
    {
        $mes = self::interpretar($texto);

        if ($mes === null || $mes->primerDia->greaterThan(CarbonImmutable::now())) {
            return self::actual();
        }

        return $mes;
    }

    private static function interpretar(?string $texto): ?self
    {
        if ($texto === null || preg_match('/^(\d{4})-(\d{2})$/', $texto, $partes) !== 1) {
            return null;
        }

        [, $anio, $mes] = $partes;

        // ⚠️ El mes se comprueba acá y no se le deja a Carbon: `createFromFormat` acepta el
        // 13 y lo convierte en enero del año siguiente, sin quejarse.
        if ((int) $mes < 1 || (int) $mes > 12) {
            return null;
        }

        // ⚠️ El `!` es el arreglo del bug de arriba: pone en cero todo lo que el formato no
        // nombra, así que el día es 1 y la hora 00:00:00. Sin él, el día es el de hoy.
        return new self(CarbonImmutable::createFromFormat('!'.self::FORMATO, $anio.'-'.$mes));
    }

    /** El valor para el `<input type="month">` y para la URL. */
    public function etiqueta(): string
    {
        return $this->primerDia->format(self::FORMATO);
    }

    /** «septiembre de 2026», para el título de la pantalla. */
    public function nombre(): string
    {
        return self::NOMBRES[$this->primerDia->month].' de '.$this->primerDia->year;
    }

    public function primerDia(): CarbonImmutable
    {
        return $this->primerDia;
    }

    /**
     * Los días que la pantalla lista: del 1 al último del mes y, si el mes es el actual,
     * hasta hoy. Un mes que todavía no terminó no lista los días que no pasaron, como el
     * sistema viejo.
     *
     * @return list<CarbonImmutable>
     */
    public function dias(): array
    {
        $ultimo = $this->ultimoDiaListado();
        $dias = [];

        for ($dia = $this->primerDia; $dia->lessThanOrEqualTo($ultimo); $dia = $dia->addDay()) {
            $dias[] = $dia;
        }

        return $dias;
    }

    /**
     * El límite de arriba de la consulta, **abierto**.
     *
     * ⚠️ Abierto y no «23:59:59»: el sistema viejo consulta `whereBetween($dia 00:00:00,
     * $dia 23:59:59)` y una fecha guardada tiene microsegundos, así que un pedido de las
     * 23:59:59,5 no entra en ningún día del mes. Hoy no hay ninguno —la empresa cierra a las
     * nueve de la noche, el pedido más tarde del mes medido es de las 20:56— pero eso es una
     * casualidad del horario, no una razón para dejar el agujero.
     */
    public function despuesDelUltimoDia(): CarbonImmutable
    {
        return $this->ultimoDiaListado()->addDay();
    }

    public function esElActual(): bool
    {
        return $this->primerDia->isSameMonth(CarbonImmutable::now());
    }

    /** Siempre hay un mes anterior: el primer pedido es del 2020 pero nada lo exige. */
    public function anterior(): self
    {
        // Sin desborde posible: `primerDia` es siempre un día 1.
        return new self($this->primerDia->subMonth());
    }

    /** El mes que viene, salvo que ya estemos en el último que tiene sentido mirar. */
    public function siguiente(): ?self
    {
        return $this->esElActual() ? null : new self($this->primerDia->addMonth());
    }

    private function ultimoDiaListado(): CarbonImmutable
    {
        return $this->esElActual()
            ? CarbonImmutable::now()->startOfDay()
            : $this->primerDia->endOfMonth()->startOfDay();
    }
}
