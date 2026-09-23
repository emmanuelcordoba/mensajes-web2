<?php

use App\Models\User;

test('the user model stores emails lowercase and trimmed', function () {
    $user = User::factory()->create(['email' => ' Juan.Perez@Mail.COM ']);

    expect($user->fresh()->email)->toBe('juan.perez@mail.com');
});

test('updating the profile stores the email lowercase', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'Nuevo.Email@Mail.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('nuevo.email@mail.com');
});

test('the profile rejects an email that only differs in case from another user', function () {
    User::factory()->create(['email' => 'ocupado@mail.com']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'Ocupado@Mail.com',
        ])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->not->toBe('ocupado@mail.com');
});
