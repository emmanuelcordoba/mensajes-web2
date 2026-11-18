<?php

use App\Support\VersionDeLaApp;
use Illuminate\Http\Request;

/*
| Qué versión de la app mandó cada pedido.
|
| Existe porque faltaba la respuesta a «¿y qué versión corre ese teléfono?». En noviembre un
| solo aparato mandó 3.179 ubicaciones de más en 19 horas y de las 23 cabeceras que llegaron
| ninguna decía la versión, así que no se podía distinguir un error ya arreglado de uno
| nuevo. Desde la 2.3.14 las apps mandan `X-App-Version`.
|
| ⚠️ Lo que estos tests sujetan es que **es dato de afuera**: lo escribe el cliente, va a
| parar a un registro que después se lee, y cualquiera con `curl` puede poner lo que quiera.
*/

function conLaCabecera(?string $valor): Request
{
    $peticion = Request::create('/api/actualizarubicacion', 'POST');

    if ($valor !== null) {
        $peticion->headers->set(VersionDeLaApp::CABECERA, $valor);
    }

    return $peticion;
}

test('it reads the version the app sends', function () {
    expect(VersionDeLaApp::de(conLaCabecera('2.3.14')))->toBe('2.3.14');
});

test('without the header it is null, which is an answer in itself', function () {
    // Las versiones anteriores a la 2.3.14 no la mandan, y saber eso es justamente el dato.
    expect(VersionDeLaApp::de(conLaCabecera(null)))->toBeNull()
        ->and(VersionDeLaApp::de(conLaCabecera('')))->toBeNull();
});

test('a prerelease still reads as a version', function () {
    expect(VersionDeLaApp::de(conLaCabecera('2.4.0-beta')))->toBe('2.4.0-beta');
});

test('anything that is not a version is cleaned out', function () {
    // ⚠️ Va a un registro que se lee en el panel. Un salto de línea ahí parte el renglón en
    // dos y deja escribir una línea entera inventada.
    //
    // Se comprueban las propiedades y no la cadena exacta que queda: lo que importa es que
    // no sobreviva nada con lo que se pueda escribir en el registro, no cuál es el recorte.
    $limpia = VersionDeLaApp::de(conLaCabecera("2.3.14\r\nTope de pedidos superado"));

    expect($limpia)->toStartWith('2.3.14')
        ->and($limpia)->not->toContain("\n", "\r", ' ')
        ->and(mb_strlen((string) $limpia))->toBeLessThanOrEqual(20);
});

test('a long one is cut', function () {
    expect(VersionDeLaApp::de(conLaCabecera(str_repeat('9', 500))))
        ->toHaveLength(20);
});

test('something with nothing usable is null and not an empty string', function () {
    expect(VersionDeLaApp::de(conLaCabecera('@@@ ###')))->toBeNull();
});
