<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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

test('an unused token still works on its last day', function () {
    $token = User::factory()->create()->createToken('app-clientes')->plainTextToken;

    $this->travel(179)->days();

    $this->withToken($token)->getJson('/api/token-probe')->assertOk();
});

test('a token expires after 180 days without use', function () {
    $token = User::factory()->create()->createToken('app-clientes')->plainTextToken;

    $this->travel(180)->days();
    $this->travel(1)->minutes();

    $this->withToken($token)->getJson('/api/token-probe')->assertUnauthorized();
});

test('using a token pushes its expiry forward', function () {
    $token = User::factory()->create()->createToken('app-cadetes')->plainTextToken;

    $this->travel(100)->days();
    $this->withToken($token)->getJson('/api/token-probe')->assertOk();

    // 200 days after it was issued, but only 100 since it was last used.
    $this->travel(100)->days();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/token-probe')->assertOk();
});

test('expired tokens are pruned every day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'sanctum:prune-expired'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
