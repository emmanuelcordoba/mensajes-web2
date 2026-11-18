<?php

use Laravel\Fortify\Features;

/*
| El registro web.
|
| Va detrás de `REGISTRO_ABIERTO` y cerrado por defecto —ver `config/fortify.php`—, así
| que cada test se saltea solo según cómo esté: los dos que prueban la puerta cuando está
| cerrada, y el que prueba que no hay puerta cuando está abierta. El salteo va por test y
| no en un `beforeEach`, igual que en SecurityTest.
|
| ⚠️ Los dos que no se saltean nunca son los que importan, y a propósito no miran este
| entorno: el del defecto, que saca la variable y vuelve a leer la config, y el del
| enlace, que se compara contra la lista de features. Un test que mira `env()` prueba la
| máquina en la que corre, y acá la suite corre sin la variable mientras CI la copia de
| `.env.example`, donde está en `false`.
*/

test('registration screen can be rendered', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('panel.inicio', absolute: false));
});

test('closed is what it is without the variable', function () {
    // ⚠️ Lo que esto defiende es el valor por defecto de `config/fortify.php`, y no cómo
    // está configurado este entorno: un entorno nuevo, o uno al que se le olvidó la
    // variable, tiene que quedar cerrado. Del otro lado están los datos de 9.075 personas
    // y 1.734 imágenes de DNI, en una URL pública.
    //
    // ⚠️ La variable se saca y el archivo se vuelve a leer, en lugar de mirar `env()`.
    // Mirar el entorno probaba el entorno: acá la suite corre sin la variable, pero CI
    // copia `.env.example`, donde está en `false`. Las dos cosas quieren decir cerrado,
    // y el test se caía igual. Así también sigue valiendo en una máquina que la tenga en
    // `true` para probar el alta.
    $fortify = sinLaVariableDelRegistro(fn () => require config_path('fortify.php'));

    expect($fortify['features'])->not->toContain(Features::registration());
});

test('with it closed there is no door', function () {
    $this->skipIfFortifyHas(Features::registration());

    // La ruta no existe, así que se pide por URL y no por nombre.
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('the sign up link follows the variable', function () {
    // El enlace sale de la misma lista de features, no de una copia: si se abriera el
    // registro y el enlace no volviera, nadie encontraría la puerta abierta. Por eso se
    // compara contra la lista y no contra `false`, que sería cierto sólo mientras esté
    // cerrado.
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where(
            'canRegister',
            Features::enabled(Features::registration()),
        ));
});

/**
 * Lee algo como si `REGISTRO_ABIERTO` no estuviera en el entorno, y lo deja como estaba.
 *
 * ⚠️ Hay que sacarla de los tres lados de los que `env()` puede leerla: `$_ENV`,
 * `$_SERVER` y `putenv`. El repositorio de Laravel es inmutable, así que no se puede
 * limpiar a través suyo, pero lee los adaptadores en el momento.
 */
function sinLaVariableDelRegistro(Closure $leer): mixed
{
    $antes = [
        'env' => $_ENV['REGISTRO_ABIERTO'] ?? null,
        'server' => $_SERVER['REGISTRO_ABIERTO'] ?? null,
        'putenv' => getenv('REGISTRO_ABIERTO'),
    ];

    unset($_ENV['REGISTRO_ABIERTO'], $_SERVER['REGISTRO_ABIERTO']);
    putenv('REGISTRO_ABIERTO');

    try {
        return $leer();
    } finally {
        if ($antes['env'] !== null) {
            $_ENV['REGISTRO_ABIERTO'] = $antes['env'];
        }
        if ($antes['server'] !== null) {
            $_SERVER['REGISTRO_ABIERTO'] = $antes['server'];
        }
        if ($antes['putenv'] !== false) {
            putenv("REGISTRO_ABIERTO={$antes['putenv']}");
        }
    }
}
