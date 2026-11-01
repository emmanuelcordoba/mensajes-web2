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
     * ⚠️ **`date_format` Y NO `date`, Y ES LA PARTE QUE MÁS IMPORTA.** `date` acepta
     * cualquier cosa que PHP sepa leer, y lo que PHP lee en `05/11/1990` es el 11 de
     * MAYO. Alguien que escribe el 5 de noviembre queda guardado con otra fecha, válida
     * y equivocada, que no delata nadie: el campo es DATE, el valor es plausible y el
     * ETL no tiene de qué agarrarse. Medido: con `date` pasaban `05/11/1990`,
     * `11-05-1990` y `1990-5-1`, y las tres quedaban reinterpretadas.
     *
     * Las dos reglas juntas no se llevan —lo dice la documentación de Laravel— y además
     * no hace falta: `date_format:Y-m-d` ya rechaza lo que no es una fecha.
     *
     * ⚠️ Esto NO rompe a quien ya manda datos. Las cuatro pantallas usan un selector de
     * fecha, que manda `aaaa-mm-dd`; y del lado de la app se comprobó contra el sistema
     * viejo, que guarda el texto tal como llega: las 752 postulaciones de producción
     * están en `aaaa-mm-dd`, sin una sola excepción.
     *
     * @return list<string>
     */
    public static function reglas(): array
    {
        return [
            'date_format:Y-m-d',
            'after_or_equal:'.self::masVieja()->toDateString(),
            'before_or_equal:'.self::masNueva()->toDateString(),
        ];
    }

    /**
     * Los mensajes, para el campo que los necesite.
     *
     * Se pasa el nombre del campo porque en postulaciones y en cadetes se llama igual pero
     * no tiene por qué: el que los pide dice cuál es el suyo.
     *
     * @return array<string, string>
     */
    public static function mensajes(string $campo = 'fecha_nacimiento'): array
    {
        return [
            // Sin esto el mensaje sale en inglés: el `locale` es `es` pero no hay
            // carpeta `lang/`, así que una regla sin mensaje propio cae en el texto que
            // trae el framework.
            $campo.'.date_format' => 'La fecha de nacimiento va como aaaa-mm-dd, por ejemplo 1990-11-25.',
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
