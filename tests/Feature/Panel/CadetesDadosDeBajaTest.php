<?php

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\LogEstadoPedido;
use App\Models\Mensaje;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCadete;
use App\Services\BorradoDefinitivoDeCadete;

/*
| Los cadetes dados de baja, y el borrado definitivo.
|
| Lo que esta pantalla tiene que garantizar son tres cosas, y ninguna es que la lista se
| vea: que sea de administradores, que el borrado se lleve TODO lo que es del cadete —si
| algo queda apuntándolo, la clave foránea lo rechaza— y que NO se lleve los pedidos, que
| son de los clientes. Y una cuarta, que es del oficio: que lo que el listado ofrece sea
| exactamente lo que el servidor después acepta.
*/

/**
 * Un cadete dado de baja como lo deja la baja de verdad: con su cuenta dada de baja.
 *
 * ⚠️ Pasa por `BajaDeCadete` y no por un `delete()` suelto, porque lo que se prueba acá es
 * lo que viene después de una baja real. Un cadete borrado a mano se queda con la cuenta
 * activa, que es justo el caso raro que el listado marca aparte.
 *
 * @param  array<string, mixed>  $atributos
 */
function unCadeteDadoDeBaja(array $atributos = []): Cadete
{
    $cadete = Cadete::factory()->create($atributos);

    (new BajaDeCadete)->dar($cadete);

    return Cadete::withTrashed()->findOrFail($cadete->id);
}

test('only an administrator sees the ones given up', function () {
    unCadeteDadoDeBaja(['numero_movil' => 11]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('cadetes/dados-de-baja'));
});

test('the button is offered to an administrator and not to an employee', function () {
    // El botón se dibuja con lo que contesta el servidor y no con un `if` de rol en la
    // pantalla: es la misma disciplina que el resto del listado.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes')
        ->assertInertia(fn ($pagina) => $pagina->where('puede_ver_dados_de_baja', true)->etc());

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(fn ($pagina) => $pagina->where('puede_ver_dados_de_baja', false)->etc());
});

test('the ones still working are not in this list', function () {
    $trabajando = Cadete::factory()->create(['numero_movil' => 10]);
    $deBaja = unCadeteDadoDeBaja(['numero_movil' => 20]);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertInertia(fn ($pagina) => $pagina
            ->has('cadetes.data', 1)
            ->where('cadetes.data.0.id', $deBaja->id)
            ->etc());

    // Y al revés: el listado de todos los días no muestra a los dados de baja. Lo hace el
    // scope de SoftDeletes, y se comprueba igual porque es la mitad del sentido de esto.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(fn ($pagina) => $pagina
            ->has('cadetes.data', 1)
            ->where('cadetes.data.0.id', $trabajando->id)
            ->etc());
});

test('the account travels although the account was given up too', function () {
    // ⚠️ La trampa de la pantalla vieja, escrita en su propio comentario: la baja de un
    // cadete da de baja su cuenta, así que la relación normal devuelve null para TODAS las
    // filas y la columna diría «sin cuenta» justo en el caso normal.
    $cadete = unCadeteDadoDeBaja(['numero_movil' => 33]);
    $email = User::withTrashed()->findOrFail($cadete->user_id)->email;

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('cadetes.data.0.cuenta.email', $email)
            ->where('cadetes.data.0.cuenta.de_baja', true)
            ->etc());
});

test('the last one given up comes first', function () {
    // Es lo que alguien está buscando cuando entra acá.
    $viejo = unCadeteDadoDeBaja(['numero_movil' => 1]);
    $nuevo = unCadeteDadoDeBaja(['numero_movil' => 2]);

    Cadete::withTrashed()->whereKey($viejo->id)->update(['deleted_at' => now()->subDays(10)]);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('cadetes.data.0.id', $nuevo->id)
            ->where('cadetes.data.1.id', $viejo->id)
            ->etc());
});

test('the definitive deletion takes everything of its own, and leaves the orders', function () {
    $cadete = Cadete::factory()->create(['numero_movil' => 44]);
    $cuenta = $cadete->user_id;

    // Todo lo que es suyo.
    Mensaje::factory()->count(2)->create(['cadete_id' => $cadete->id]);
    Postulacion::factory()->create(['cadete_id' => $cadete->id]);
    ActividadCadete::factory()->create(['cadete_id' => $cadete->id]);
    MovimientoCobranzaSaldo::factory()->create(['cadete_id' => $cadete->id]);

    // Y un pedido que entregó, que es de un cliente.
    $pedido = Pedido::factory()->create(['cadete_id' => $cadete->id]);
    $log = LogEstadoPedido::factory()->create([
        'pedido_id' => $pedido->id,
        'cadete_id' => $cadete->id,
    ]);

    (new BajaDeCadete)->dar($cadete);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo", ['confirmacion' => '44'])
        ->assertRedirect('/panel/cadetes/dados-de-baja');

    // La fila ya no está, ni dada de baja: eso es lo que distingue esto de la baja.
    expect(Cadete::withTrashed()->whereKey($cadete->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($cuenta)->exists())->toBeFalse()
        // ⚠️ `withTrashed()` en todas: la baja las había dejado dadas de baja, y el
        // borrado tiene que sacar la fila. Si quedara, la clave foránea apuntaría a un
        // cadete que no existe, que es justo lo que la base no acepta.
        ->and(Mensaje::withTrashed()->where('cadete_id', $cadete->id)->count())->toBe(0)
        ->and(Postulacion::withTrashed()->where('cadete_id', $cadete->id)->count())->toBe(0)
        ->and(ActividadCadete::query()->where('cadete_id', $cadete->id)->count())->toBe(0)
        ->and(MovimientoCobranzaSaldo::query()->where('cadete_id', $cadete->id)->count())->toBe(0);

    // ⚠️ **Y EL PEDIDO SIGUE ESTANDO.** Es de un cliente, con su valor y su garantía: lo
    // único que pierde es el cadete. El log tampoco se borra, por lo mismo.
    expect($pedido->fresh())->not->toBeNull()
        ->and($pedido->fresh()->cadete_id)->toBeNull()
        ->and($log->fresh())->not->toBeNull()
        ->and($log->fresh()->cadete_id)->toBeNull();
});

test('without the mobile number written nothing is deleted', function () {
    // No se deshace, así que la confirmación no es un adorno: es la misma que pide la
    // pantalla de Corrección de datos del sistema viejo.
    $cadete = unCadeteDadoDeBaja(['numero_movil' => 55]);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo", ['confirmacion' => '56'])
        ->assertRedirect()
        ->assertSessionHas('danger', 'Para confirmar hay que escribir el número de móvil 55.');

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo")
        ->assertSessionHas('danger');

    expect(Cadete::withTrashed()->whereKey($cadete->id)->exists())->toBeTrue();
});

test('a cadete who is still working is not offered the deletion, and the route refuses it', function () {
    $trabajando = Cadete::factory()->create(['numero_movil' => 66]);

    expect(BorradoDefinitivoDeCadete::porQueNo($trabajando))
        ->toContain('no está dado de baja');

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$trabajando->id}/borrado-definitivo", ['confirmacion' => '66'])
        ->assertForbidden();

    expect(Cadete::query()->whereKey($trabajando->id)->exists())->toBeTrue();
});

test('an account in use by something else blocks the deletion', function () {
    // El único bloqueo que queda del sistema viejo, y es el que protege de verdad: la
    // cuenta se va con el cadete, y si figura en otra cosa esa otra cosa queda sin cuenta.
    $cadete = unCadeteDadoDeBaja(['numero_movil' => 77]);
    Cliente::factory()->create(['user_id' => $cadete->user_id]);

    // El listado no dibuja un botón que iba a fallar: muestra el motivo.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('cadetes.data.0.borrado.permitido', false)
            ->where('cadetes.data.0.borrado.motivo',
                'La cuenta del móvil 77 figura en 1 registros que no son de este cadete '
                .'(un comercio, o mensajes o cobranzas de otro), que quedarían sin cuenta.')
            ->etc());

    // Y es la misma regla que el servidor aplica, no una copia de la pantalla.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo", ['confirmacion' => '77'])
        ->assertForbidden();

    expect(Cadete::withTrashed()->whereKey($cadete->id)->exists())->toBeTrue();
});

test('its own messages do not block the deletion', function () {
    // ⚠️ La otra puerta del mismo bloqueo: los mensajes de ESTE cadete los escribió su
    // cuenta, así que contarlos como «en uso» bloquearía todos los borrados.
    $cadete = Cadete::factory()->create(['numero_movil' => 88]);
    Mensaje::factory()->create([
        'cadete_id' => $cadete->id,
        'user_id' => $cadete->user_id,
    ]);

    (new BajaDeCadete)->dar($cadete);

    expect(BorradoDefinitivoDeCadete::porQueNo(Cadete::withTrashed()->findOrFail($cadete->id)))
        ->toBeNull();
});

test('an employee cannot delete definitively', function () {
    $cadete = unCadeteDadoDeBaja(['numero_movil' => 99]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo", ['confirmacion' => '99'])
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->delete("/panel/cadetes/{$cadete->id}/borrado-definitivo", ['confirmacion' => '99'])
        ->assertForbidden();

    expect(Cadete::withTrashed()->whereKey($cadete->id)->exists())->toBeTrue();
});

test('the list says what the deletion takes, to be able to say it before', function () {
    $cadete = Cadete::factory()->create(['numero_movil' => 121]);
    Mensaje::factory()->count(3)->create(['cadete_id' => $cadete->id]);
    Pedido::factory()->count(2)->create(['cadete_id' => $cadete->id]);
    ActividadCadete::factory()->create(['cadete_id' => $cadete->id]);
    Postulacion::factory()->create(['cadete_id' => $cadete->id]);

    (new BajaDeCadete)->dar($cadete);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes/dados-de-baja')
        ->assertInertia(fn ($pagina) => $pagina
            // ⚠️ Los mensajes y la postulación se cuentan con `withTrashed()`: la baja los
            // dio de baja, y si no se contaran la confirmación diría «se borran 0
            // mensajes» justo cuando se va a borrar la conversación entera.
            ->where('cadetes.data.0.arrastra.mensajes', 3)
            ->where('cadetes.data.0.arrastra.postulacion', true)
            ->where('cadetes.data.0.arrastra.actividades', 1)
            ->where('cadetes.data.0.arrastra.pedidos', 2)
            ->etc());
});
