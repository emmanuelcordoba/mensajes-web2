<?php

use App\Http\Controllers\Panel\CadeteController;
use App\Http\Controllers\Panel\ClienteController;
use App\Http\Controllers\Panel\CobranzaSaldoController;
use App\Http\Controllers\Panel\CobranzaSemanalController;
use App\Http\Controllers\Panel\ColaController;
use App\Http\Controllers\Panel\ConfiguracionController;
use App\Http\Controllers\Panel\EstadisticasController;
use App\Http\Controllers\Panel\HorarioAtencionController;
use App\Http\Controllers\Panel\InicioController;
use App\Http\Controllers\Panel\MapaController;
use App\Http\Controllers\Panel\MensajeController;
use App\Http\Controllers\Panel\PedidoController;
use App\Http\Controllers\Panel\PendienteController;
use App\Http\Controllers\Panel\PostulacionController;
use App\Http\Controllers\Panel\PublicidadAppController;
use App\Http\Controllers\Panel\TarifaController;
use App\Http\Controllers\Panel\UserController;
use App\Models\Rol;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | El panel
    |--------------------------------------------------------------------------
    |
    | Dos grupos, como en el sistema viejo: lo que un comercio necesita para trabajar
    | y lo que es administrar el negocio. El corte es el mismo que hace el menú
    | lateral, y está escrito dos veces a propósito —acá y en App\Support\Navegacion—
    | porque son dos preguntas distintas: quién entra a la ruta y qué se le ofrece.
    | NavegacionTest compara las dos respuestas, entrada por entrada y rol por rol.
    |
    | ⚠️ Durante el porteo, las pantallas que faltaban iban a PendienteController, que no
    | mostraba más que su nombre: la ruta y su nombre definitivo existían desde el
    | principio y al hacer la pantalla cambiaba el controlador y nada más. **Ya no queda
    | ninguna**, así que PendienteController y `panel/pendiente.tsx` no los usa nadie y
    | están para borrar. De Inicio falta contenido —dos de sus tres columnas—, pero eso lo
    | dice la pantalla misma, no otro controlador.
    |
    */
    Route::prefix('panel')->name('panel.')->group(function () {

        /*
        | Acceso compartido: admin, empleado y los comercios.
        |
        | El middleware dice quién entra a la ruta; a qué fila llega lo dice
        | PedidoPolicy, y las dos cosas hacen falta: sin la segunda, un comercio veía
        | los pedidos de cualquier otro mandando el id (SEC-7).
        */
        Route::middleware('rol:'.implode(',', [Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO]))
            ->group(function () {
                // La pantalla principal del sistema viejo: el alta de un pedido, con la
                // lista de cadetes activos al costado. Es la más cargada de todas.
                Route::get('/', InicioController::class)->name('inicio');

                Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');

                /*
                | El alta, que es la columna del medio de Inicio.
                |
                | ⚠️ Acá y no en el grupo de oficina, que es donde uno esperaría: en el
                | sistema viejo `Route::resource('pedidos')` vive en el grupo compartido y
                | el formulario tiene su rama de `restringido`. Medido: 19.431 pedidos
                | cargados por una cuenta de comercio desde la web, 310 en los últimos 180
                | días. Lo que el comercio no puede es poner el precio ni elegir cadete, y
                | eso lo niega `GuardarPedidoRequest`, no el middleware.
                */
                Route::post('pedidos', [PedidoController::class, 'store'])->name('pedidos.store');

                /*
                | Cuánto sale un viaje. Es `ajax/pedidos/cotizar` del sistema viejo, que
                | también está al alcance de un comercio.
                |
                | ⚠️ **Fuera de `pedidos/`**: no es de ningún pedido —se pregunta antes de
                | que exista— y además `pedidos/{pedido}`, que se registra abajo, la
                | capturaría con el id «cotizacion».
                */
                Route::get('tarifa', TarifaController::class)->name('tarifa');

                /*
                | El comprobante para imprimir. Es `pdf/pedido` del sistema viejo, que
                | tampoco arma un PDF: es una página que se imprime sola.
                |
                | Contesta 404 cuando el pedido es de otro comercio, por la política.
                */
                Route::get('pedidos/{pedido}/cupon', [PedidoController::class, 'cupon'])->name('pedidos.cupon');

                // Después del listado y de las rutas exactas, que no las captura.
                Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('pedidos.show');

                /*
                | Borrar un pedido está en el grupo compartido porque un comercio puede
                | borrar los suyos: en el sistema viejo `ajax/pedidos/eliminar` vive en el
                | grupo que incluye a `restringido` y comprueba la pertenencia adentro,
                | contestando 404. Acá eso lo dice `PedidoPolicy::delete()`.
                |
                | Las dos del modal de la lista de espera NO están acá: ver más abajo.
                */
                Route::delete('pedidos/{pedido}', [PedidoController::class, 'destroy'])->name('pedidos.destroy');
            });

        /*
        | Administración: sólo admin y empleado.
        |
        | Es el mismo recorte que el menú viejo hace con
        | @if(Auth::user()->tieneRol(['admin','empleado'])), y que el sistema viejo
        | también lleva al middleware. Ver SEC-4: hasta el 2026-08-12 todo el panel
        | colgaba de un único grupo que no distinguía entre los tres roles, y una
        | cuenta de comercio llegaba a la administración de usuarios y podía crearse un
        | administrador.
        */
        Route::middleware('rol:'.implode(',', [Rol::ADMIN, Rol::EMPLEADO]))
            ->group(function () {
                /*
                | El chat con los cadetes.
                |
                | ⚠️ `mensajes/cadetes` va ANTES de `mensajes/{cadete}`, o el parámetro la
                | captura: es el buscador con el que se empieza una conversación nueva.
                |
                | El cadete es opcional en el listado, como en el sistema viejo
                | —`/mensajes/{cadete_id?}`—: sin él la pantalla muestra las conversaciones
                | y ninguna abierta.
                */
                /*
                | Las dos del modal de la lista de espera, que es una pantalla de oficina.
                |
                | ⚠️ Acá y no en el grupo compartido, que es donde están en el sistema viejo:
                | `ajax/pedidos/getById` y `ajax/pedidos/actualizar` viven en el grupo
                | `rol:empleado,admin`. Adentro no comprueban la pertenencia **y no hace
                | falta**: la oficina alcanza todos los pedidos. El control de pertenencia es
                | lo que necesitan las rutas del grupo compartido, no éstas.
                |
                | `detalle` contesta JSON, como el del cadete: el modal lo pide al abrirse.
                */
                Route::get('pedidos/{pedido}/detalle', [PedidoController::class, 'detalle'])->name('pedidos.detalle');
                Route::patch('pedidos/{pedido}/asignacion', [PedidoController::class, 'asignar'])->name('pedidos.asignar');

                Route::get('mensajes/cadetes', [MensajeController::class, 'cadetes'])->name('mensajes.cadetes');
                Route::get('mensajes/{cadete?}', [MensajeController::class, 'index'])->name('mensajes.index');
                Route::post('mensajes/{cadete}', [MensajeController::class, 'enviar'])->name('mensajes.enviar');

                /*
                | El buscador de comercios del alta de pedidos, y el alta rápida del modal.
                |
                | ⚠️ Van en el grupo de oficina porque el formulario viejo esconde las dos
                | cosas detrás de `@if(tieneRol(['admin','empleado']))`: un comercio no
                | elige a nombre de quién carga el pedido —es él— ni da de alta clientes.
                |
                | Las dos contestan JSON: el formulario las pide con un `fetch` sin salir
                | de la pantalla, que es el punto del atajo.
                */
                Route::get('clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');
                Route::post('clientes/rapido', [ClienteController::class, 'crearRapido'])->name('clientes.rapido');

                // ⚠️ `create` antes de cualquier `{cliente}` que se agregue después, o
                // lo captura. Vale para los cuatro recursos de acá abajo.
                /*
                | Los clientes.
                |
                | ⚠️ `clientes/create` antes de `clientes/{cliente}`, o el parámetro la
                | captura. La baja suma `rol:admin` sobre el grupo, como en el sistema
                | viejo: se lleva los pedidos del cliente y su cuenta de la app, y eso no
                | queda al alcance de cualquier rol. Lo decide ClientePolicy, que además
                | dice POR QUÉ no cuando el cliente está asignado a una cuenta de panel.
                */
                Route::get('clientes/create', [ClienteController::class, 'create'])->name('clientes.create');
                Route::get('clientes', [ClienteController::class, 'index'])->name('clientes.index');
                Route::post('clientes', [ClienteController::class, 'store'])->name('clientes.store');
                Route::get('clientes/{cliente}/edit', [ClienteController::class, 'edit'])->name('clientes.edit');
                Route::patch('clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
                Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');
                /*
                | El carrusel de la app. Borrar suma `rol:admin` por la política, como en
                | el sistema viejo, donde `ajax.publicidadapp.destroy` llevaba ese
                | middleware junto con el borrado de usuarios.
                |
                | ⚠️ `create` antes de `{publicidadApp}`, o el parámetro la captura.
                */
                Route::get('publicidad-app/create', [PublicidadAppController::class, 'create'])->name('publicidad-app.create');
                Route::get('publicidad-app', [PublicidadAppController::class, 'index'])->name('publicidad-app.index');
                Route::post('publicidad-app', [PublicidadAppController::class, 'store'])->name('publicidad-app.store');
                Route::get('publicidad-app/{publicidadApp}/edit', [PublicidadAppController::class, 'edit'])->name('publicidad-app.edit');
                Route::patch('publicidad-app/{publicidadApp}', [PublicidadAppController::class, 'update'])->name('publicidad-app.update');
                Route::patch('publicidad-app/{publicidadApp}/visibilidad', [PublicidadAppController::class, 'cambiarVisibilidad'])->name('publicidad-app.visibilidad');
                Route::delete('publicidad-app/{publicidadApp}', [PublicidadAppController::class, 'destroy'])->name('publicidad-app.destroy');

                /*
                | El horario de atención es UNA fila: no hay listado ni alta, sólo la
                | pantalla y su guardado. Ver HorarioAtencionController.
                */
                Route::get('horario-de-atencion', [HorarioAtencionController::class, 'edit'])->name('horario-de-atencion.index');
                Route::patch('horario-de-atencion', [HorarioAtencionController::class, 'update'])->name('horario-de-atencion.update');

                /*
                | Los cadetes.
                |
                | ⚠️ Todo lo que empieza con un segmento literal va ANTES de
                | `cadetes/{cadete}`, o el parámetro lo captura: valen las seis primeras,
                | incluidas las cuatro pantallas que todavía no están.
                |
                | Las tres últimas no son CRUD y son del listado: ponerlo en la cola o
                | sacarlo, y bloquearlo. El bloqueo se escribe en `users`, así que PATCH y
                | DELETE sobre el mismo recurso —`cadetes/{cadete}/bloqueo`— son poner y
                | quitar el motivo. La baja suma `rol:admin` por la política, y CadetePolicy
                | además dice POR QUÉ no cuando la cobranza está abierta.
                */
                Route::get('cadetes/create', [CadeteController::class, 'create'])->name('cadetes.create');
                /*
                | Las postulaciones.
                |
                | ⚠️ `postulaciones/create` antes de `postulaciones/{postulacion}`, o el
                | parámetro la captura. Las tres rutas del legajo van antes que las del
                | cadete por legibilidad, no por precedencia: tienen tres segmentos y no
                | chocan con `cadetes/{cadete}`.
                |
                | Contratar es POST y no PATCH porque crea dos filas nuevas —el cadete y su
                | cuenta—, y vive en su propio sub-recurso: el viejo mandaba las dos
                | decisiones a un PUT y las separaba leyendo el `name` del botón apretado.
                */
                Route::get('cadetes/postulaciones/create', [PostulacionController::class, 'create'])->name('cadetes.postulaciones.create');
                Route::get('cadetes/postulaciones', [PostulacionController::class, 'index'])->name('cadetes.postulaciones.index');
                Route::post('cadetes/postulaciones', [PostulacionController::class, 'store'])->name('cadetes.postulaciones.store');
                Route::get('cadetes/postulaciones/{postulacion}', [PostulacionController::class, 'show'])->name('cadetes.postulaciones.show');
                Route::post('cadetes/postulaciones/{postulacion}/contratacion', [PostulacionController::class, 'contratar'])->name('cadetes.postulaciones.contratar');
                Route::delete('cadetes/postulaciones/{postulacion}', [PostulacionController::class, 'destroy'])->name('cadetes.postulaciones.destroy');
                /*
                | La cola, y el mostrador por donde se entra a ella.
                |
                | ⚠️ El POST lleva `throttle` porque es un formulario que prueba
                | contraseñas. El sistema viejo no tenía ninguno, y muchas de esas
                | contraseñas son PIN de 3 a 8 dígitos —así las pedía el panel viejo—, o
                | sea que probarlas todas era cuestión de dejarlo corriendo. Veinte por
                | minuto es de sobra para un mostrador: una persona tarda diez segundos.
                */
                Route::get('cadetes/cola', [ColaController::class, 'index'])->name('cadetes.cola');
                Route::post('cadetes/cola', [ColaController::class, 'agregar'])
                    ->middleware('throttle:20,1')
                    ->name('cadetes.cola.agregar');

                /*
                | Y lo que la central le hace a la cola desde Inicio. No es el mostrador:
                | el que las dispara es un usuario del panel, ya autenticado.
                */
                Route::post('cadetes/cola/{cadete}/al-final', [ColaController::class, 'alFinal'])->name('cadetes.cola.al-final');
                Route::delete('cadetes/cola/{cadete}', [ColaController::class, 'sacar'])->name('cadetes.cola.sacar');

                /*
                | ⚠️ Va DESPUÉS de `cola/{cadete}`, o `cadetes/cola` entraría por el
                | comodín con el id «cola».
                */
                Route::delete('cadetes/cola', [ColaController::class, 'limpiar'])->name('cadetes.cola.limpiar');
                /*
                | Cobranzas semanales.
                |
                | ⚠️ `cobranzas/semanal/cuotas` va ANTES de `cobranzas/semanal/{cadete}`, o
                | el parámetro la captura: las cuotas generales no son de ningún cadete,
                | son dos filas, una por vehículo.
                |
                | ⚠️ Esta modalidad está muerta —10 pagos en toda la historia, el último en
                | 2022— y se porta igual, con su aritmética. Ver PANEL-21.
                */
                Route::patch('cadetes/cobranzas/semanal/cuotas', [CobranzaSemanalController::class, 'guardarMontos'])->name('cadetes.cobranzas.semanal.cuotas');
                Route::get('cadetes/cobranzas/semanal', [CobranzaSemanalController::class, 'index'])->name('cadetes.cobranzas.semanal.index');
                Route::get('cadetes/cobranzas/semanal/{cadete}', [CobranzaSemanalController::class, 'show'])->name('cadetes.cobranzas.semanal.show');
                Route::get('cadetes/cobranzas/semanal/{cadete}/ciclo', [CobranzaSemanalController::class, 'ciclo'])->name('cadetes.cobranzas.semanal.ciclo');
                Route::post('cadetes/cobranzas/semanal/{cadete}/ciclo', [CobranzaSemanalController::class, 'abrirCiclo'])->name('cadetes.cobranzas.semanal.abrir');
                Route::post('cadetes/cobranzas/semanal/{cadete}/pagos', [CobranzaSemanalController::class, 'registrarPago'])->name('cadetes.cobranzas.semanal.pagos');
                Route::patch('cadetes/cobranzas/semanal/{cadete}/vencimiento', [CobranzaSemanalController::class, 'extender'])->name('cadetes.cobranzas.semanal.extender');
                /*
                | Cobranzas por saldo.
                |
                | ⚠️ Las tres rutas con parámetro van DESPUÉS de la del listado, que es
                | exacta. Cargar saldo es POST sobre su propio sub-recurso —escribe un
                | movimiento nuevo—, y cambiar la modalidad es PATCH sobre el cadete.
                */
                Route::get('cadetes/cobranzas/saldo', [CobranzaSaldoController::class, 'index'])->name('cadetes.cobranzas.saldo.index');
                Route::get('cadetes/cobranzas/saldo/{cadete}', [CobranzaSaldoController::class, 'show'])->name('cadetes.cobranzas.saldo.show');
                Route::post('cadetes/cobranzas/saldo/{cadete}/cargas', [CobranzaSaldoController::class, 'cargar'])->name('cadetes.cobranzas.saldo.cargar');
                Route::patch('cadetes/cobranzas/saldo/{cadete}/modalidad', [CobranzaSaldoController::class, 'cambiarModalidad'])->name('cadetes.cobranzas.modalidad');
                Route::get('cadetes', [CadeteController::class, 'index'])->name('cadetes.index');
                Route::post('cadetes', [CadeteController::class, 'store'])->name('cadetes.store');
                Route::get('cadetes/{cadete}/edit', [CadeteController::class, 'edit'])->name('cadetes.edit');
                Route::patch('cadetes/{cadete}', [CadeteController::class, 'update'])->name('cadetes.update');
                Route::delete('cadetes/{cadete}', [CadeteController::class, 'destroy'])->name('cadetes.destroy');
                Route::patch('cadetes/{cadete}/estado', [CadeteController::class, 'cambiarEstado'])->name('cadetes.estado');

                /*
                | El buscador de cadetes del selector de Inicio: la cola la tiene la
                | pantalla, y esto es para el resto.
                |
                | ⚠️ Contesta JSON como `detalle`, y por el mismo motivo: son nombres de
                | personas y no tienen que viajar en cada refresco de una pantalla que está
                | abierta todo el día en una oficina.
                */
                Route::get('cadetes/buscar', [CadeteController::class, 'buscar'])->name('cadetes.buscar');

                /*
                | ⚠️ Contesta JSON y no una pantalla de Inertia. La pide el diálogo de
                | detalle de Inicio con un `fetch`, como el panel viejo pedía
                | `ajax/cadetes/getcadete`: son datos de una persona y se traen recién
                | cuando alguien los abre, no en cada refresco de la pantalla.
                |
                | (Hasta el alta de pedidos ésta era la única que contestaba JSON. Ahora son
                | seis, y todas por el mismo motivo: lo que no se mira no viaja.)
                */
                Route::get('cadetes/{cadete}/detalle', [CadeteController::class, 'detalle'])->name('cadetes.detalle');
                Route::patch('cadetes/{cadete}/bloqueo', [CadeteController::class, 'bloquear'])->name('cadetes.bloquear');
                Route::delete('cadetes/{cadete}/bloqueo', [CadeteController::class, 'desbloquear'])->name('cadetes.desbloquear');

                /*
                | Las cuentas del panel.
                |
                | ⚠️ `users/create` y `users/comercios` van antes de `users/{user}`, o el
                | parámetro las captura. Quién puede qué lo decide UserPolicy: el grupo
                | deja entrar a empleado y admin, y de ahí para adentro la cuenta de un
                | administrador sólo la toca otro administrador (SEC-9).
                */
                Route::get('users/create', [UserController::class, 'create'])->name('users.create');
                Route::get('users/comercios', [UserController::class, 'comercios'])->name('users.comercios');
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
                Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

                /*
                | Los parámetros del negocio. Se leen al cotizar un pedido y al cobrarle
                | al cadete, así que el validador exige lo mismo que el CHECK de la
                | columna: ver GuardarConfiguracionRequest.
                |
                | El `update` lo usa también el selector de demora de la barra de arriba,
                | que es otra fila de la misma tabla.
                */
                Route::get('configuraciones', [ConfiguracionController::class, 'index'])->name('configuraciones.index');
                Route::patch('configuraciones/{configuracion}', [ConfiguracionController::class, 'update'])->name('configuraciones.update');
                Route::get('mapa', MapaController::class)->name('mapa');
                Route::get('estadisticas', EstadisticasController::class)->name('estadisticas.index');
            });
    });
});

require __DIR__.'/settings.php';
