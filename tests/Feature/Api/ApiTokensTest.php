<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum'])->get('/api/token-probe', fn (Request $request) => [
        'id' => $request->user()->id,
    ]);
});

test('a token reaches the API', function () {
    $user = User::factory()->create();
    $token = $user->createToken('app-cadetes')->plainTextToken;

    $this->withToken($token)->getJson('/api/token-probe')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('the API rejects requests without a token', function () {
    $this->getJson('/api/token-probe')->assertUnauthorized();
});
