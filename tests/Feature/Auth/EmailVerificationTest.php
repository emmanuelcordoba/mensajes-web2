<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('panel.inicio', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    /*
    | ⚠️ **El id tiene que ser uno que no exista, y acá decía `123`.**
    |
    | Que 123 sea inválido depende de dónde quedó `users_id_seq` cuando este test corre, y
    | eso depende de cuántos usuarios creó TODO lo que corrió antes. El día que la suite
    | creciera lo justo, al usuario de este test le tocaba el id 123, el enlace pasaba a ser
    | válido, el email se verificaba y el test fallaba —sólo con la suite completa, nunca
    | corriéndolo solo—. Pasó: lo destapó la tanda de tests de la API de la app.
    |
    | `max('id') + 1` es libre por construcción, mire el test desde donde lo mire.
    */
    $idQueNoExiste = User::query()->max('id') + 1;

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $idQueNoExiste, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to the panel from verification prompt', function () {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('panel.inicio', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('panel.inicio', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
