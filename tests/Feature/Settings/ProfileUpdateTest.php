<?php

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();

    // ⚠️ Se borra con soft delete, y no es una preferencia: seis de las ocho claves
    // foráneas que apuntan a `users` son NO ACTION —pedidos, cadetes, clientes,
    // mensajes, logs y movimientos de cobranza—, así que borrar la fila de un usuario
    // con cualquier historia falla en la base. La cuenta deja de existir para el
    // sistema y su historia sigue en pie.
    //
    // `fresh()` no sirve para comprobarlo: usa newQueryWithoutScopes(), así que
    // devuelve el modelo igual.
    $this->assertSoftDeleted($user);

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});

test('a user with history cannot be removed from the table at all', function () {
    // Es lo que hace que el soft delete sea obligatorio y no una elección.
    $user = User::factory()->create();
    Pedido::factory()->create(['user_id' => $user->id]);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete()))
        ->toThrow(QueryException::class);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
