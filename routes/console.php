<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

/*
| La poda de Telescope.
|
| ⚠️ Sin esto la tabla crece para siempre. Treinta días es a propósito más que el día que
| deja ver el plan de Laravel Cloud: la idea de haber instalado esto es justamente poder
| mirar lo de la semana pasada.
*/
Schedule::command('telescope:prune --hours=720')->daily();
