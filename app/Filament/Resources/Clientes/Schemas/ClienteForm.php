<?php

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use App\Models\User;
use App\Services\CuentaDeCliente;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * El formulario de un cliente.
 *
 * Todo lo que de un cliente se puede corregir, incluido el email y la contraseña de su
 * cuenta de la app. Esa última parte vivía sólo en la pantalla de Cuentas, que es un
 * lugar razonable para encontrarla y no el lugar donde uno está cuando se entera de que
 * el email está mal escrito.
 *
 * ⚠️ Escrito a mano, como el de cuentas. El `--generate` pone una entrada por columna, y
 * entre ellas `fcm_token`: el token de notificaciones del teléfono de esa persona. No es
 * un dato que se edite, y a la vista es con lo que se le mandan notificaciones a un
 * dispositivo ajeno. Tampoco tiene sentido `nombre_mostrado`, que PostgreSQL calcula y
 * rechaza si se le escribe.
 *
 * ⚠️ `cuenta_email` y `cuenta_password` NO son columnas de `clientes`. Se deshidratan
 * como cualquier campo para que lleguen a `EditCliente::handleRecordUpdate()`, que los
 * saca de los datos del cliente y se los pasa a `CuentaDeCliente`. Si alguna vez dejan de
 * sacarse de ahí, el `Fillable` del modelo los ignora sin avisar.
 */
class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('El cliente')
                    ->columns(2)
                    ->components([
                        TextInput::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Es el que gana sobre el de la app.'),

                        TextInput::make('direccion')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('telefono')
                            ->label('Teléfono')
                            ->tel()
                            ->required()
                            ->maxLength(20),

                        TextInput::make('nombre_empleado')
                            ->label('Nombre del empleado')
                            ->maxLength(255)
                            ->helperText('Quién atiende, si el cliente es un comercio.'),

                        TextInput::make('numero')
                            ->label('Número')
                            ->numeric()
                            // ⚠️ NO SE PIDE AL CREAR, y es una decisión que el modelo dejó
                            // planteada. El número sale de `clientes_numero_seq` por
                            // DEFAULT; si el panel deja escribirlo a mano la secuencia no
                            // avanza, y un `nextval` posterior choca con el número puesto
                            // a mano. El UNIQUE lo atrapa —no se corrompe nada— pero el
                            // alta falla, y falla en el alta de otro cliente, que es
                            // donde nadie lo va a entender. El panel viejo sí lo ofrecía:
                            // allá el número se calculaba en PHP mirando la colección
                            // entera (PERF-1) y los repetidos eran moneda corriente.
                            //
                            // Al editar sí se puede, porque es la única forma de
                            // corregir uno mal puesto.
                            ->visibleOn('edit')
                            ->required()
                            // Cuenta también los dados de baja, porque el UNIQUE los
                            // cuenta: un cliente de baja sigue ocupando su número.
                            ->unique(ignoreRecord: true)
                            ->helperText('Lo asigna el sistema al crear. Cambialo sólo para corregir.'),
                    ]),

                Section::make('La persona de la app')
                    ->columns(2)
                    ->collapsed()
                    ->description('Los nombres los escribe la aplicación del cliente, y desde acá se pueden corregir.')
                    ->components([
                        // ⚠️ Editables desde el 2026-11-08; antes eran grises. Lo que hacía
                        // dudar era DATA-8: hasta entonces la app pisaba el `nombre` del
                        // cliente con el de la persona cada vez que se abría, y así se
                        // perdieron 51 nombres de comercio que no están en ningún backup.
                        // Por acá eso no puede volver a pasar:
                        // `Cliente::ponerNombresDePersona()` rearma el `nombre` sólo si
                        // todavía era el de la persona, y si el panel le puso otro lo
                        // respeta. Corregir un apellido mal escrito no se lleva puesto el
                        // nombre del comercio.
                        TextInput::make('nombres')
                            ->label('Nombres')
                            ->maxLength(60),

                        TextInput::make('apellidos')
                            ->label('Apellidos')
                            ->maxLength(60),

                        TextInput::make('nombre_mostrado')
                            ->label('Nombre que ve el sistema')
                            ->disabled()
                            // ⚠️ Columna generada: la calcula PostgreSQL con el `nombre` si
                            // lo hay y si no con nombres y apellidos. Escribirla es un
                            // error de la base, así que ni se manda.
                            ->dehydrated(false)
                            ->helperText('Lo calcula la base: gana el nombre de arriba, y si está vacío usa nombres y apellidos.'),

                        TextInput::make('plataforma')
                            ->label('Origen')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Cargado desde el panel')
                            ->formatStateUsing(fn (?string $state): ?string => $state === Cliente::PLATAFORMA_APP
                                ? 'App'
                                : $state),
                    ]),

                Section::make('La cuenta de la app')
                    ->columns(2)
                    // Al crear no hay cuenta todavía: la del cliente la crea la app al
                    // registrarse. Ver `CuentaDeCliente`.
                    ->visibleOn('edit')
                    ->collapsed()
                    ->description(fn (?Cliente $record): string => self::descripcion($record))
                    ->components([
                        // Cuando no hay cuenta no se muestran campos vacíos que no guardan
                        // nada: se dice por qué. La pregunta la contesta el servicio, para
                        // que el formulario y la escritura no puedan discrepar.
                        Placeholder::make('sin_cuenta')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (?Cliente $record): string => (string) CuentaDeCliente::porQueNo($record ?? new Cliente))
                            ->visible(fn (?Cliente $record): bool => ! self::tieneCuenta($record)),

                        TextInput::make('cuenta_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Igual que en cuentas: se normaliza al salir del campo, antes
                            // de validar. El modelo baja a minúsculas al guardar, así que
                            // si se validara «Ana@X.com» tal cual, `unique` no encontraría
                            // la fila guardada como «ana@x.com», pasaría la validación y
                            // reventaría contra el índice único. Ver DATA-7.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                'cuenta_email',
                                mb_strtolower(trim((string) $state)),
                            ))
                            // ⚠️ Contra `users`, que es donde vive el email: no es una
                            // columna de `clientes`. Y lo que se ignora es la CUENTA de
                            // este cliente, no el cliente: `ignoreRecord` compararía
                            // contra `clientes`, donde la columna no existe, y entonces
                            // guardar sin tocar el email daría «ya está en uso».
                            //
                            // Sin `withoutTrashed()`: una cuenta dada de baja sigue
                            // ocupando su email, porque `users.email` es UNIQUE a secas.
                            // Es la mitad de DATA-7 que no era obvia.
                            ->unique(
                                table: User::class,
                                column: 'email',
                                ignorable: fn (?Cliente $record): ?User => $record?->user,
                            )
                            ->visible(fn (?Cliente $record): bool => self::tieneCuenta($record))
                            ->helperText('Es con lo que la persona entra a la app.'),

                        TextInput::make('cuenta_password')
                            ->label('Contraseña nueva')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            // ⚠️ La regla sale del servicio y no de `Password::default()`:
                            // en producción el default pide 12 caracteres con símbolos y la
                            // app sólo pide 6. Ver `CuentaDeCliente::reglasDeContrasena()`.
                            ->rules(CuentaDeCliente::reglasDeContrasena())
                            ->validationMessages(['min' => CuentaDeCliente::mensajeDeContrasena()])
                            ->same('cuenta_password_confirmation')
                            // En blanco significa «no la toques». Sin esto, corregir un
                            // teléfono le cambiaría la contraseña a la persona.
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visible(fn (?Cliente $record): bool => self::tieneCuenta($record))
                            ->helperText('Dejala en blanco para no cambiarla. Si la cambiás, se cierra lo que tenga abierto.'),

                        TextInput::make('cuenta_password_confirmation')
                            ->label('Repetir contraseña')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (Get $get): bool => filled($get('cuenta_password')))
                            // No es una columna: sólo existe para comparar.
                            ->dehydrated(false)
                            ->visible(fn (?Cliente $record): bool => self::tieneCuenta($record)),
                    ]),
            ]);
    }

    /** Si hay cuenta que editar. La pregunta la contesta el servicio, no el formulario. */
    protected static function tieneCuenta(?Cliente $cliente): bool
    {
        return CuentaDeCliente::porQueNo($cliente ?? new Cliente) === null;
    }

    /**
     * Lo que la sección de la cuenta explica antes de que alguien escriba.
     *
     * ⚠️ El aviso del cadete no es cosmético. 6 de las 7.316 cuentas de clientes son
     * además de un cadete, porque es la misma persona: cambiarle el email ahí le cambia
     * también el ingreso a la app de cadete. Es correcto —es una sola cuenta— y es
     * exactamente el tipo de cosa que alguien descubre después.
     */
    protected static function descripcion(?Cliente $cliente): string
    {
        if (! self::tieneCuenta($cliente)) {
            return 'La cuenta con la que la persona entra a la aplicación.';
        }

        $aviso = 'Cambiar el email obliga a verificarlo de nuevo: hasta que no lo haga, la '
            .'app no la deja entrar, y le manda el código a la casilla nueva.';

        if ($cliente?->user?->cadete !== null) {
            $aviso .= ' ⚠️ Esta misma cuenta es la de un cadete, así que el cambio también '
                .'le cambia el ingreso a la app de cadete.';
        }

        return $aviso;
    }
}
