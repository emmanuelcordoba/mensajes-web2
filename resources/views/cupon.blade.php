{{--
    El comprobante de un pedido, para imprimir.

    Es `resources/views/pdfs/pedido.blade.php` del sistema viejo. ⚠️ **No genera un PDF**,
    pese al nombre que tiene allá: es una página que se imprime sola al cargar. El único PDF
    de verdad del sistema viejo es el listado de cadetes.

    ⚠️ **Las medidas están en centímetros y no se tocan.** Se imprime sobre un cupón
    preimpreso: los márgenes de página, el corrimiento de 7,5 cm de la fecha y los 2,2 cm de
    alto del detalle son dónde caen los recuadros del papel.

    ⚠️ **El CSS está acá y no en un CDN.** El sistema viejo trae Bootstrap 3 de
    stackpath.bootstrapcdn.com, así que el cupón sale desarmado si la oficina está sin
    internet o si el CDN cambia. Las reglas de abajo son las que esa hoja le daba a las
    clases que esta vista usa —la grilla de doce, el `h4`, el `rem` de 10 px— escritas a mano.

    ⚠️ **Los montos se imprimen sin los ceros de más.** En el sistema viejo son floats y PHP
    los escribe «3000»; acá la columna es `NUMERIC(12,2)` y llega «3000.00». El papel tiene
    que salir igual.
--}}
@php
    /**
     * El monto como lo escribía el sistema viejo: sin los decimales que no dicen nada.
     *
     * Vive en el modelo porque la API de la app del cadete muestra los mismos montos en la
     * pantalla del teléfono, y el formato tiene que ser uno solo.
     */
    $plata = static fn (?string $monto): string => \App\Models\Pedido::montoParaMostrar($monto) ?? '';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensajes Cadetería - Pedido {{ $pedido->id }}</title>

    <style>
        /* Los 10 px de los que Bootstrap 3 hace sus `rem`. */
        html {
            font-size: 10px;
        }

        body {
            margin: 0;
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            line-height: 1.428571429;
            /* Los dos del cupón viejo. */
            font-size: 1.48rem;
            color: #020A96;
        }

        .container-fluid {
            padding-right: 15px;
            padding-left: 15px;
        }

        /* Las filas de la grilla flotan, así que necesitan cerrarse. */
        .row {
            margin-right: -15px;
            margin-left: -15px;
        }

        .row::after {
            display: table;
            clear: both;
            content: " ";
        }

        .col-xs-2,
        .col-xs-5,
        .col-xs-7 {
            position: relative;
            min-height: 1px;
            padding-right: 15px;
            padding-left: 15px;
            float: left;
        }

        .col-xs-2 { width: 16.66666667%; }
        .col-xs-5 { width: 41.66666667%; }
        .col-xs-7 { width: 58.33333333%; }

        h4 {
            margin-top: 10px;
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 500;
            line-height: 1.1;
        }

        .text-center { text-align: center; }

        .pr-0 { padding-right: 0; }

        .px-0 {
            padding-right: 0;
            padding-left: 0;
        }

        .m-0 { margin: 0; }

        @page {
            margin: .7cm;
            margin-left: .52cm;
            margin-right: .52cm;
        }

        .fecha {
            font-size: 1.2rem;
            margin-bottom: 1.5cm;
            margin-left: 7.5cm;
        }

        #detalle {
            height: 2.2cm;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-xs-7 pr-0">
                <div class="fecha">
                    Fecha {{ \App\Support\Momento::fecha($pedido->created_at) }}<br>
                    Cupón N° {{ $pedido->numero }}
                </div>
            </div>
        </div>

        <div class="row">
            @if ($pedido->cliente)
                <div class="col-xs-5 px-0">
                    Cliente: N° {{ $pedido->cliente->numero }} - {{ $pedido->cliente->nombre_mostrado }}
                </div>
            @else
                <div class="col-xs-5 px-0">Cliente: {{ $pedido->nombre_cliente }}</div>
            @endif

            <div class="col-xs-2 pr-0">Vale por: $ {{ $plata($pedido->garantia) }}</div>
        </div>

        <div class="row">
            <div class="col-xs-7 px-0">
                Dirección: {{ $pedido->direccion }}<br>
                Destino: {{ $pedido->destino }}
            </div>
        </div>

        <div class="row">
            <div class="col-xs-7 px-0">Teléfono: {{ $pedido->telefono }}</div>
        </div>

        <div class="row">
            <div id="detalle" class="col-xs-7 px-0">Detalle: {{ $pedido->detalle }}</div>
        </div>

        <div class="row">
            <div class="col-xs-7 px-0">Responsable: {{ $pedido->responsable }}</div>
        </div>

        <div class="row">
            @if ($pedido->cadete)
                <div class="col-xs-5 px-0">
                    Móvil N° {{ $pedido->cadete->numero_movil }} - {{ $pedido->cadete->nombre_completo }}
                </div>
            @else
                <div class="col-xs-5 px-0">Móvil: sin asignar</div>
            @endif

            <div class="col-xs-2 px-0 text-center">
                Valor del viaje
                <h4 class="m-0"><b>$ {{ $plata($pedido->valor) }}</b></h4>
            </div>
        </div>
    </div>

    <script>
        // Igual que el sistema viejo: se abre en una pestaña, sale el diálogo de impresión.
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
