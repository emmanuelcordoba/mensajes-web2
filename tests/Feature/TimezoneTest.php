<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('timezone_probes', function (Blueprint $table) {
        $table->id();
        $table->timestampTz('happened_at');
    });
});

test('now() is stored as the current instant', function () {
    $before = time();

    DB::table('timezone_probes')->insert(['happened_at' => now()]);

    $stored = (int) DB::table('timezone_probes')->value(DB::raw('extract(epoch from happened_at)'));

    expect($stored)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());
});

test('dates are compared by the local day', function () {
    // 22:30 in Tucumán is already the next day in UTC.
    DB::table('timezone_probes')->insert(['happened_at' => '2026-09-18 22:30:00-03']);

    expect(DB::table('timezone_probes')->whereDate('happened_at', '2026-09-18')->count())->toBe(1);
});
