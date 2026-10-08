<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Entre qué dos días puede estar la fecha de nacimiento de un cadete.
 *
 * ⚠️ **EXISTE PORQUE LA BASE NO LO SUJETA.** La columna es `DATE NOT NULL` sin CHECK de
 * rango, así que acepta el año 190: hay 32 cadetes con fechas imposibles, 41 personas
 * contando las postulaciones (DATA-10). El rango se exige en cada pantalla por donde se
 * escribe, y son cuatro: el formulario de cadetes del panel, el de Filament, el de
 * postulaciones y el perfil que el cadete edita desde la app.
 *
 * ⚠️ **Y está acá porque son cuatro lectores de UN número.** Estaba copiado en los cuatro,
 * con dos redacciones distintas del mismo mensaje. Subir el mínimo de 16 años en tres de
 * los cuatro y olvidarse del que falta no da ningún error: deja una puerta abierta y la
 * descubre el primero que entra por ahí. Hay un test que compara los cuatro lectores.
 */
final class FechaDeNacimiento
{
    /** La edad mínima para ser cadete. */
    public const ANOS_MINIMOS = 16;

    /** Y el otro extremo, que no es una edad de jubilación: es el filtro de lo imposible. */
    public const ANOS_MAXIMOS = 90;

    /** El día más viejo que se acepta. */
    public static function masVieja(): CarbonImmutable
    {
        return CarbonImmutable::today()->subYears(self::ANOS_MAXIMOS);
    }

    /** Y el más nuevo: el del que justo cumple la edad mínima hoy. */
    public static function masNueva(): CarbonImmutable
    {
        return CarbonImmutable::today()->subYears(self::ANOS_MINIMOS);
    }

    /**
     * Las reglas de validación, para sumarlas a `required` o a `nullable`.
     *
     * @return list<string>
     */
    public static function reglas(): array
    {
        return [
            'date',
            'after_or_equal:'.self::masVieja()->toDateString(),
            'before_or_equal:'.self::masNueva()->toDateString(),
        ];
    }

    /**
     * Los dos mensajes, para el campo que los necesite.
     *
     * Se pasa el nombre del campo porque en postulaciones y en cadetes se llama igual pero
     * no tiene por qué: el que los pide dice cuál es el suyo.
     *
     * @return array<string, string>
     */
    public static function mensajes(string $campo = 'fecha_nacimiento'): array
    {
        return [
            $campo.'.after_or_equal' => 'La fecha de nacimiento no puede ser de hace más de '
                .self::ANOS_MAXIMOS.' años.',
            $campo.'.before_or_equal' => 'Tiene que tener al menos '.self::ANOS_MINIMOS.' años.',
        ];
    }

    /** El texto de ayuda que va debajo del campo. */
    public static function ayuda(): string
    {
        return 'Entre '.self::ANOS_MINIMOS.' y '.self::ANOS_MAXIMOS.' años.';
    }
}
