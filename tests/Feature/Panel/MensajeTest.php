<?php

use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\Rol;
use App\Models\User;

/*
| El chat entre la central y los cadetes.
|
| Lo que esta pantalla tiene que garantizar: que se sepa quién escribió cada mensaje sin
| depender de que la cuenta del cadete siga viva, que abrir una conversación la marque
| leída, y que listar 444 conversaciones no sean 445 consultas.
*/

/** Un cadete con su cuenta, que es la que escribe desde la app. */
function unCadeteQueEscribe(array $atributos = []): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cuenta = User::factory()->create(['rol_id' => $rol->id]);

    return Cadete::factory()->create(array_merge($atributos, ['user_id' => $cuenta->id]));
}

test('an employee reaches the chat and a shop does not', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mensajes')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('mensajes/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/mensajes')
        ->assertForbidden();
});

test('the list shows the last message of each conversation, newest first', function () {
    $uno = unCadeteQueEscribe(['numero_movil' => 11]);
    $otro = unCadeteQueEscribe(['numero_movil' => 22]);

    Mensaje::factory()->create([
        'cadete_id' => $uno->id,
        'user_id' => $uno->user_id,
        'mensaje' => 'el viejo',
        'created_at' => now()->subHours(3),
    ]);
    Mensaje::factory()->create([
        'cadete_id' => $uno->id,
        'user_id' => $uno->user_id,
        'mensaje' => 'el último del uno',
        'created_at' => now()->subHours(2),
    ]);
    Mensaje::factory()->create([
        'cadete_id' => $otro->id,
        'user_id' => $otro->user_id,
        'mensaje' => 'el último del otro',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mensajes')
        ->assertInertia(function ($pagina) {
            $lista = $pagina->toArray()['props']['conversaciones'];

            expect($lista)->toHaveCount(2)
                ->and($lista[0]['movil'])->toBe(22)
                ->and($lista[0]['mensaje'])->toBe('el último del otro')
                ->and($lista[1]['mensaje'])->toBe('el último del uno');
        });
});

test('who wrote each message is two columns, not three relations', function () {
    // ⚠️ La vista vieja preguntaba `$mensaje->cadete->user->id == $mensaje->user->id`.
    // Acá se compara `mensajes.user_id` con `cadetes.user_id`.
    $cadete = unCadeteQueEscribe();
    $central = usuarioCon(Rol::EMPLEADO);

    Mensaje::factory()->create([
        'cadete_id' => $cadete->id,
        'user_id' => $cadete->user_id,
        'mensaje' => 'lo escribió el cadete',
        'created_at' => now()->subMinutes(2),
    ]);
    Mensaje::factory()->create([
        'cadete_id' => $cadete->id,
        'user_id' => $central->id,
        'mensaje' => 'lo escribió la central',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($central)
        ->get("/panel/mensajes/{$cadete->id}")
        ->assertInertia(function ($pagina) {
            $mensajes = $pagina->toArray()['props']['abierta']['mensajes'];

            expect($mensajes[0]['del_cadete'])->toBeTrue()
                ->and($mensajes[1]['del_cadete'])->toBeFalse();
        });
});

test('a conversation opens even if the account of the cadete is gone', function () {
    // ⚠️ Esto es un 500 en el panel viejo: `cadete->user` es null cuando la cuenta está
    // dada de baja —`User` usa bajas lógicas— y la vista le pedía el `id` sin guarda.
    $cadete = unCadeteQueEscribe();
    $central = usuarioCon(Rol::EMPLEADO);

    Mensaje::factory()->create([
        'cadete_id' => $cadete->id,
        'user_id' => $cadete->user_id,
        'mensaje' => 'antes de que se fuera',
    ]);

    $cadete->user?->delete();

    $this->actingAs($central)
        ->get("/panel/mensajes/{$cadete->id}")
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['abierta']['mensajes'][0]['del_cadete'],
        )->toBeTrue());
});

test('opening a conversation marks it read on the web side, and only that one', function () {
    $uno = unCadeteQueEscribe();
    $otro = unCadeteQueEscribe();

    Mensaje::factory()->count(3)->create([
        'cadete_id' => $uno->id,
        'user_id' => $uno->user_id,
        'leido_web' => false,
    ]);
    Mensaje::factory()->create([
        'cadete_id' => $otro->id,
        'user_id' => $otro->user_id,
        'leido_web' => false,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/mensajes/{$uno->id}")
        ->assertSuccessful();

    expect(Mensaje::query()->where('cadete_id', $uno->id)->sinLeerEnLaWeb()->count())->toBe(0)
        // ⚠️ Y no toca `leido_app`: los dos «leído» son independientes, porque el mismo
        // mensaje se marca en dos lados distintos.
        ->and(Mensaje::query()->where('cadete_id', $uno->id)->sinLeerEnLaApp()->count())->toBe(3)
        ->and(Mensaje::query()->where('cadete_id', $otro->id)->sinLeerEnLaWeb()->count())->toBe(1);
});

test('conversations of cadetes who were given up are not listed', function () {
    // ⚠️ Esto es un 500 si no se filtra, y NO es un caso raro: 407 de las 444
    // conversaciones de producción son de cadetes dados de baja. `Cadete` usa bajas
    // lógicas, así que la relación no los devuelve y pedirle el móvil a null revienta.
    //
    // El listado viejo lo filtraba con `->has('cadete')`. Su refresco de cada cinco
    // segundos, no: ahí la lista se rompía con un TypeError en el navegador.
    $vigente = unCadeteQueEscribe(['numero_movil' => 11]);
    $dadoDeBaja = unCadeteQueEscribe(['numero_movil' => 22]);

    Mensaje::factory()->create([
        'cadete_id' => $vigente->id,
        'user_id' => $vigente->user_id,
        'mensaje' => 'de uno que sigue',
    ]);
    Mensaje::factory()->create([
        'cadete_id' => $dadoDeBaja->id,
        'user_id' => $dadoDeBaja->user_id,
        'mensaje' => 'de uno que se fue',
    ]);

    $dadoDeBaja->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mensajes')
        ->assertSuccessful()
        ->assertInertia(function ($pagina) {
            $lista = $pagina->toArray()['props']['conversaciones'];

            expect($lista)->toHaveCount(1)
                ->and($lista[0]['movil'])->toBe(11);
        });
});

test('the unread count travels with each conversation', function () {
    $cadete = unCadeteQueEscribe();

    Mensaje::factory()->count(2)->create([
        'cadete_id' => $cadete->id,
        'user_id' => $cadete->user_id,
        'leido_web' => false,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mensajes')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['conversaciones'][0]['sin_leer'],
        )->toBe(2));
});

test('listing the conversations is a fixed number of queries', function () {
    // ⚠️ El `index` viejo hacía una consulta por conversación para contar los no leídos:
    // con 444 conversaciones eso es 445 consultas por carga, y la pantalla se recarga sola
    // cada cinco segundos.
    foreach (range(1, 12) as $i) {
        $cadete = unCadeteQueEscribe(['numero_movil' => 500 + $i]);
        Mensaje::factory()->count(2)->create([
            'cadete_id' => $cadete->id,
            'user_id' => $cadete->user_id,
            'leido_web' => false,
        ]);
    }

    $empleado = usuarioCon(Rol::EMPLEADO);

    DB::enableQueryLog();

    $this->actingAs($empleado)->get('/panel/mensajes')->assertSuccessful();

    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Sesión, usuario, menú, las dos del listado y poco más. Lo que importa es que NO
    // crezca con la cantidad de conversaciones: con una por cada una, esto sería 12 más.
    expect($consultas)->toBeLessThan(15);
});

test('only the last hundred messages come back, with a way to ask for the rest', function () {
    // ⚠️ El panel viejo traía la conversación entera en cada carga y en cada refresco. La
    // más larga de producción tiene 351 mensajes.
    $cadete = unCadeteQueEscribe();

    foreach (range(1, 105) as $i) {
        Mensaje::factory()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $cadete->user_id,
            'mensaje' => 'número '.$i,
            'created_at' => now()->subMinutes(200 - $i),
        ]);
    }

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/mensajes/{$cadete->id}")
        ->assertInertia(function ($pagina) {
            $abierta = $pagina->toArray()['props']['abierta'];

            expect($abierta['mensajes'])->toHaveCount(100)
                ->and($abierta['total'])->toBe(105)
                ->and($abierta['anteriores'])->toBe(5)
                // Los últimos, y en orden de lectura: el más viejo de los cien primero.
                ->and($abierta['mensajes'][0]['mensaje'])->toBe('número 6')
                ->and($abierta['mensajes'][99]['mensaje'])->toBe('número 105');
        });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/mensajes/{$cadete->id}?completa=1")
        ->assertInertia(function ($pagina) {
            $abierta = $pagina->toArray()['props']['abierta'];

            expect($abierta['mensajes'])->toHaveCount(105)
                ->and($abierta['anteriores'])->toBe(0);
        });
});

test('sending a message leaves it read on the web and unread on the app', function () {
    $cadete = unCadeteQueEscribe();
    $empleado = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($empleado)
        ->from("/panel/mensajes/{$cadete->id}")
        ->post("/panel/mensajes/{$cadete->id}", ['mensaje' => 'Pasá por la oficina.'])
        ->assertRedirect("/panel/mensajes/{$cadete->id}");

    $mensaje = Mensaje::query()->sole();

    expect($mensaje->mensaje)->toBe('Pasá por la oficina.')
        ->and($mensaje->user_id)->toBe($empleado->id)
        ->and($mensaje->leido_web)->toBeTrue()
        ->and($mensaje->leido_app)->toBeFalse();
});

test('an empty message does not get sent', function () {
    $cadete = unCadeteQueEscribe();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from("/panel/mensajes/{$cadete->id}")
        ->post("/panel/mensajes/{$cadete->id}", ['mensaje' => ''])
        ->assertSessionHasErrors('mensaje');

    expect(Mensaje::query()->count())->toBe(0);
});

test('a message with no text is shown, not crashed on', function () {
    // La columna es nullable y hay mensajes históricos en null: leerlos tiene que andar,
    // aunque escribir uno nuevo así no se pueda.
    $cadete = unCadeteQueEscribe();
    Mensaje::factory()->create([
        'cadete_id' => $cadete->id,
        'user_id' => $cadete->user_id,
        'mensaje' => null,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/mensajes/{$cadete->id}")
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['abierta']['mensajes'][0]['mensaje'],
        )->toBeNull());
});

test('the cadetes to write to are searched, not all sent in the page', function () {
    // ⚠️ El `<select>` viejo listaba los 1.768 cadetes en cada carga de la pantalla.
    unCadeteQueEscribe(['numero_movil' => 77, 'apellidos' => 'Quiroga', 'nombres' => 'Ana']);
    unCadeteQueEscribe(['numero_movil' => 88, 'apellidos' => 'Juárez', 'nombres' => 'Luis']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/mensajes/cadetes?q=quiroga')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['nombre' => 'Móvil 77 · Quiroga Ana']);

    // Por móvil sólo si lo escrito es un número, como en el resto del panel.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/mensajes/cadetes?q=88')
        ->assertJsonCount(1);
});

test('a shop cannot read or write at any of the routes', function () {
    $cadete = unCadeteQueEscribe();
    $comercio = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($comercio)->get("/panel/mensajes/{$cadete->id}")->assertForbidden();
    $this->actingAs($comercio)->post("/panel/mensajes/{$cadete->id}", ['mensaje' => 'hola'])->assertForbidden();
    $this->actingAs($comercio)->getJson('/panel/mensajes/cadetes?q=a')->assertForbidden();

    expect(Mensaje::query()->count())->toBe(0);
});
