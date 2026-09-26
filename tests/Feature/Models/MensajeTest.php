<?php

use App\Models\Cadete;
use App\Models\Mensaje;

/*
| Los mensajes entre la central y un cadete. Los dos «leído» son independientes
| porque el mismo mensaje se marca en dos lados distintos.
*/

test('a message starts unread on both sides', function () {
    $mensaje = Mensaje::factory()->create();

    expect($mensaje->leido_web)->toBeFalse()
        ->and($mensaje->leido_app)->toBeFalse();
});

test('reading it in one place does not mark it read in the other', function () {
    $cadete = Cadete::factory()->create();

    $soloApp = Mensaje::factory()->leidoEnLaApp()->create(['cadete_id' => $cadete->id]);
    $soloWeb = Mensaje::factory()->leidoEnLaWeb()->create(['cadete_id' => $cadete->id]);
    $ninguno = Mensaje::factory()->create(['cadete_id' => $cadete->id]);

    expect(Mensaje::query()->sinLeerEnLaApp()->pluck('id')->all())
        ->toBe([$soloWeb->id, $ninguno->id])
        ->and(Mensaje::query()->sinLeerEnLaWeb()->pluck('id')->all())
        ->toBe([$soloApp->id, $ninguno->id]);
});

test('a message belongs to a cadete and to whoever wrote it', function () {
    $mensaje = Mensaje::factory()->create();

    expect($mensaje->cadete)->not->toBeNull()
        ->and($mensaje->user)->not->toBeNull()
        ->and($mensaje->cadete->mensajes()->count())->toBe(1);
});

test('a deleted message stops showing up but stays in the table', function () {
    $mensaje = Mensaje::factory()->create();

    $mensaje->delete();

    expect(Mensaje::query()->count())->toBe(0)
        ->and(Mensaje::withTrashed()->count())->toBe(1);
});
