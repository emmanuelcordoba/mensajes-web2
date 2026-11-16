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

/*
| Los pedidos que nadie cerró nunca.
|
| ⚠️ Un pedido Asignado o En curso se queda así para siempre si el cadete no lo finaliza, y
| mientras tanto **traba a su cadete**: `Cadete::tienePedidoSinTerminar()` es lo que decide
| si al entregar vuelve solo a la fila. Del sistema viejo vinieron 382, el más viejo de 2020,
| y se limpiaron a mano el 16/11/2026; esto es para que no se vuelva a juntar.
|
| De madrugada porque es una escritura sobre muchas filas y a esa hora no hay nadie
| trabajando. Treinta días es holgado: el pedido más viejo que estaba de verdad en la calle
| el día de la limpieza tenía seis.
*/
Schedule::command('pedidos:limpiar-sin-terminar --aplicar')->dailyAt('04:30');
