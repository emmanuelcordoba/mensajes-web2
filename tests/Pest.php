<?php

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Un usuario con ese rol. El rol se reutiliza si ya existe: `roles.rol` es UNIQUE, y
 * dos usuarios del mismo rol comparten la fila, como en producción.
 *
 * Vive acá y no en un archivo de tests porque ya la necesitan dos. Una función
 * declarada dentro de un archivo de tests es global igual, así que el segundo que la
 * declarara reventaría por redeclaración.
 */
function usuarioCon(string $rol, ?Cliente $comercio = null): User
{
    $fila = Rol::query()->firstOrCreate(['rol' => $rol], ['display_rol' => ucfirst($rol)]);

    return User::factory()->create([
        'rol_id' => $fila->id,
        'cliente_restringido_id' => $comercio?->id,
    ]);
}
