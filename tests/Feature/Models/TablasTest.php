<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/*
| El nombre de tabla de cada modelo apunta a una tabla que existe.
|
| Esto está acá por un error concreto: `Rol` necesita declarar `$table = 'roles'`
| porque el pluralizador de Eloquent da `rols`. En PostgreSQL eso falla con «relation
| does not exist» y se nota; en MongoDB apuntaba a una colección que SÍ existía y
| estaba muerta, y el ETL cargó seis documentos equivocados sin que nada avisara. Ver
| DATA-3 y el docblock de Rol.
|
| Recorre los modelos en vez de listarlos: un modelo nuevo entra solo.
*/

test('every model points at a table that exists', function () {
    $modelos = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $archivo) {
        $clase = 'App\\Models\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $archivo->getRelativePathname(),
        );

        if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
            continue;
        }

        $modelos[$clase] = (new $clase)->getTable();
    }

    // Una guarda para que la prueba no pase en vacío si el recorrido deja de
    // encontrar los modelos. Son las 19 tablas de dominio más PersonalAccessToken.
    expect($modelos)->toHaveCount(20);

    $faltantes = array_filter($modelos, fn (string $tabla): bool => ! Schema::hasTable($tabla));

    expect($faltantes)->toBe([]);
});
