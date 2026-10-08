<?php

/*
|--------------------------------------------------------------------------
| La API, repartida por quién la consume
|--------------------------------------------------------------------------
|
| Son cuatro consumidores distintos y cada uno es una aplicación aparte, con su propio
| contrato y su propio ritmo de cambio:
|
|   routes/api/cadete-app-v1.php    La app de los cadetes.
|   routes/api/cliente-app-v1.php   La app de los clientes. (falta)
|   routes/api/cliente-app-v2.php   La misma app, contrato nuevo. (falta)
|   routes/api/user-api-v1.php      La integración de terceros, DEP-3. (falta)
|
| ⚠️ **El consumidor va primero y la versión adentro, al revés que en el sistema viejo.**
| Allá la carpeta de más afuera es la versión —`Api/v1/ClientesApp` y `Api/v2/ClientesApp`—,
| así que el código de UNA app queda partido en dos árboles que no se tocan, y para entender
| qué ve esa app hay que leer los dos. Acá cada consumidor es una carpeta y sus versiones
| cuelgan de él, que es como de verdad evolucionan: una app se actualiza entera.
|
| ⚠️ **Y el consumidor está siempre, aunque el sistema viejo no lo pusiera.** Allá
| `Api/v1/PedidoController` es el de los cadetes y `Api/v1/ClientesApp/PedidoController` el
| de los clientes: dos clases con el mismo nombre, una con dueño y la otra sin. Acá el dueño
| se nombra siempre.
|
| ⚠️ **La versión de la carpeta no siempre está en la URL, y eso es un hecho del sistema
| viejo que conviene tener a la vista.** De los cuatro, sólo la app de clientes lleva `v2/`
| en la dirección; las rutas del cadete y las de la integración no dicen ninguna versión. La
| carpeta dice qué contrato implementa el código. El día que el cadete necesite un `V2`, sus
| URL van a tener que empezar a decirlo, y hasta que todos los teléfonos actualicen van a
| convivir las dos.
|
| ⚠️ Lo que entra acá ya viene con el prefijo `/api` y el grupo de middleware `api`, que los
| pone `bootstrap/app.php`.
*/

require __DIR__.'/api/cadete-app-v1.php';
