{{--
| El padrón de cadetes en papel. Es `pdfs/cadetes.blade.php` del sistema viejo.
|
| ⚠️ **El CSS está acá y allá viene de un CDN.** La vista vieja trae Bootstrap 4 de
| jsdelivr y lo renderiza con `enable_remote`: sin internet el listado sale sin estilos, y
| cada impresión es una petición a un tercero hecha desde el servidor. Lo que sigue son las
| reglas que esa hoja le aplicaba a esta tabla —`table`, `table-striped`, `table-bordered`—
| con las medidas que les daba, así que el papel sale igual.
|
| ⚠️ `table-condensed` es de Bootstrap 3 y en la versión 4 no existe: en producción no hace
| nada, así que acá tampoco está. El relleno de las celdas es el de `.table`, 0.75rem.
|
| ⚠️ Las fuentes del sistema no existen dentro de Dompdf. La pila de Bootstrap termina en
| Helvetica, que sí es una de las catorce del PDF, y es la que sale en producción: está
| escrita para que no dependa de la que elija cada motor.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Listado de Cadetes</title>
    <style>
        @page {
            /* Las medidas de allá, que son las del papel. */
            margin: .7cm;
            margin-left: .52cm;
            margin-right: .52cm;
        }

        body {
            font-family: Helvetica, sans-serif;
            font-size: 16px;
            color: #212529;
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            /* `.table-bordered`: el borde de afuera y el de cada celda. */
            border: 1px solid #dee2e6;
        }

        th, td {
            padding: .75rem;
            border: 1px solid #dee2e6;
            text-align: left;
            vertical-align: top;
        }

        th {
            font-weight: bold;
        }

        /*
         * `.table-striped`: las filas impares.
         *
         * ⚠️ La de los títulos cuenta como la primera, así que va rayada y la primera fila
         * de datos sale blanca. Pasa porque la tabla no tiene `<thead>` —la vieja tampoco—
         * y el navegador mete las dos en el mismo `<tbody>`. Es cómo sale el papel allá.
         */
        tr:nth-child(odd) th,
        tr:nth-child(odd) td {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <th>N° Móvil</th>
            <th>Apellido</th>
            <th>Nombre</th>
            <th>Teléfono</th>
            <th>Vehículo</th>
        </tr>
        @foreach ($cadetes as $cadete)
            <tr>
                <td>{{ $cadete->numero_movil }}</td>
                <td>{{ $cadete->apellidos }}</td>
                <td>{{ $cadete->nombres }}</td>
                <td>{{ $cadete->telefono }}</td>
                {{-- ⚠️ **La etiqueta, y el listado viejo imprime el código.** La columna
                     guarda «B» o «M», y allá sale esa letra sola, en una hoja que se cuelga
                     en la oficina. Acá dice «Bicicleta» o «Motocicleta», que es lo que dice
                     la pantalla de cadetes: es lo único del papel que no sale igual. --}}
                <td>{{ \App\Models\Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
