<?php

use App\Models\Cliente;
use Illuminate\Database\QueryException;

/*
| El cliente, y sobre todo su nombre: es donde estaban los bugs de DATA-8. Lo que
| se prueba acá es que el esquema nuevo los haga imposibles, no que el modelo se
| acuerde de evitarlos.
*/

test('the name everything reads is generated, and neither writer can lose it', function () {
    // El panel escribe `nombre`; la app escribe `nombres` y `apellidos`.
    $delPanel = Cliente::factory()->create(['nombre' => 'Rotisería El Buen Sabor']);
    $deLaApp = Cliente::factory()->deLaApp()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);

    // Lo calcula PostgreSQL al insertar, y Eloquent sólo lee de vuelta la clave
    // primaria. El trait LeeLoQueEscribeLaBase lo trae, así que está disponible sin
    // refrescar: si no, un create() devolvería un cliente sin nombre.
    expect($delPanel->nombre_mostrado)->toBe('Rotisería El Buen Sabor')
        ->and($deLaApp->nombre_mostrado)->toBe('Ana Pérez');

    // Y no queda marcado como sucio: un save() posterior no intenta escribir una
    // columna generada, que PostgreSQL rechazaría.
    expect($delPanel->isDirty())->toBeFalse();

    $delPanel->update(['direccion' => 'Otra dirección 123']);

    expect($delPanel->fresh()->nombre_mostrado)->toBe('Rotisería El Buen Sabor');
});

test('a double space cannot reach the generated name, which is what DATA-8 was', function () {
    // Un solo espacio doble alcanzaba para que el panel no vinculara el pedido a
    // su cliente: 138 clientes. Acá es imposible por estructura.
    $cliente = Cliente::factory()->create(['nombre' => '  Kiosco   Don   José  ']);

    expect($cliente->nombre)->toBe('Kiosco Don José')
        ->and($cliente->fresh()->nombre_mostrado)->toBe('Kiosco Don José');

    // Y también si el nombre sale del par de la persona, donde TRIM solo no
    // alcanzaba: junta los dos campos.
    $persona = Cliente::factory()->deLaApp()->create(['nombres' => '  Ana  ', 'apellidos' => ' Pérez ']);

    expect($persona->fresh()->nombre_mostrado)->toBe('Ana Pérez');
});

test('a client with no name at all is refused by the database', function () {
    // NOT NULL sobre la columna generada es la garantía: obliga a que esté
    // `nombre`, o esté el par. La cadena vacía cuenta como ausente.
    expect(fn () => Cliente::factory()->create(['nombre' => '   ']))
        ->toThrow(QueryException::class);

    expect(fn () => Cliente::factory()->create(['nombre' => null]))
        ->toThrow(QueryException::class);
});

test('the number comes from the sequence, and repeating one is refused', function () {
    $primero = Cliente::factory()->create();
    $segundo = Cliente::factory()->create();

    expect($segundo->numero)->toBe($primero->numero + 1);

    // El panel deja escribir el número a mano, y el UNIQUE es lo que impide que
    // eso produzca un repetido. Es la diferencia con el sistema viejo, donde nada
    // lo garantizaba y por eso un contador habría sido un error.
    expect(fn () => Cliente::factory()->create(['numero' => $primero->numero]))
        ->toThrow(QueryException::class);
});

test('the app fills in the person without overwriting a name the panel chose', function () {
    // Hasta DATA-8 la app pisaba el nombre siempre, y así se perdieron 51 nombres
    // de comercio que no se pueden recuperar de los backups.
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia del Centro']);

    $comercio->ponerNombresDePersona('Ana', 'Pérez');

    expect($comercio->nombre)->toBe('Farmacia del Centro')
        ->and($comercio->nombres)->toBe('Ana');

    // Pero si el nombre del cliente todavía era el de la persona, se rearma: la
    // persona se corrigió el apellido y el cliente la sigue.
    $seguia = Cliente::factory()->create(['nombre' => 'Ana Peres', 'nombres' => 'Ana', 'apellidos' => 'Peres']);

    $seguia->ponerNombresDePersona('Ana', 'Pérez');

    expect($seguia->nombre)->toBe('Ana Pérez');
});

test('a client with no name of its own does not need one: the generated column follows', function () {
    // Los clientes de la app pueden tener `nombre` en NULL, y entonces
    // nombre_mostrado sale del par. Ponerle un `nombre` ahí sería congelarlo: la
    // próxima corrección del apellido ya no se vería.
    $cliente = Cliente::factory()->deLaApp()->create(['nombres' => 'Ana', 'apellidos' => 'Peres']);

    $cliente->ponerNombresDePersona('Ana', 'Pérez');
    $cliente->save();

    expect($cliente->nombre)->toBeNull()
        ->and($cliente->fresh()->nombre_mostrado)->toBe('Ana Pérez');
});

test('the name key folds case, accents and spacing so names can be compared in PHP', function () {
    // Es lo que usa ponerNombresDePersona() para decidir si el nombre del cliente
    // todavía era el de la persona. No usa la extensión intl, que no hay por qué
    // suponer instalada.
    expect(Cliente::claveDeNombre('  Rotisería   EL  Buen  Sabor '))
        ->toBe('rotiseria el buen sabor')
        ->and(Cliente::claveDeNombre('Peña'))->toBe('peña')
        ->and(Cliente::claveDeNombre('José Ángel Muñoz'))->toBe('jose angel muñoz')
        ->and(Cliente::claveDeNombre(null))->toBeNull();
});

test('looking up a client by name gives one or none, never a guess', function () {
    Cliente::factory()->create(['nombre' => 'Panadería La Espiga']);

    expect(Cliente::unicoConNombre('panadería la espiga'))->not->toBeNull()
        ->and(Cliente::unicoConNombre('  Panadería   La   Espiga  '))->not->toBeNull()
        ->and(Cliente::unicoConNombre('otro nombre'))->toBeNull()
        ->and(Cliente::unicoConNombre(null))->toBeNull()
        ->and(Cliente::unicoConNombre(''))->toBeNull();

    // Con dos clientes del mismo nombre no hay forma de saber cuál es, así que el
    // panel deja el pedido sin vincular en vez de adivinar (DATA-8).
    Cliente::factory()->create(['nombre' => 'Panadería La Espiga']);

    expect(Cliente::unicoConNombre('Panadería La Espiga'))->toBeNull();
});

test('accents are NOT folded in the lookup, unlike the old system', function () {
    // ⚠️ Queda anotado como diferencia de comportamiento, no como algo resuelto:
    // el sistema viejo usaba la collation de MongoDB y para él «Peña» y «Pena»
    // eran el mismo cliente. Igualarlo necesita la extensión unaccent y un índice
    // funcional. Ver el docblock de unicoConNombre().
    Cliente::factory()->create(['nombre' => 'Peña']);

    expect(Cliente::unicoConNombre('PEÑA'))->not->toBeNull()
        ->and(Cliente::unicoConNombre('Pena'))->toBeNull();
});
