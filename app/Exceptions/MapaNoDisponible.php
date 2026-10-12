<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Google Maps no contestó, o no está configurado.
 *
 * Existe para que la distancia no se invente nunca: quien la pide recibe esto y decide qué
 * hacer, y los dos caminos deciden distinto. Cotizar devuelve 503 —lo único que ese endpoint
 * hace es dar un precio, así que no hay a qué caer—. El alta crea el pedido igual, sin precio
 * y en «Sin asignar», para que lo cotice una persona desde el panel: que el proveedor de mapas
 * esté caído no le hace perder el alta al consumidor.
 */
class MapaNoDisponible extends RuntimeException {}
