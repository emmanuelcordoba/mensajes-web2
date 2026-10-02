<?php

namespace App\Filament\Resources\Cadetes\Schemas;

use App\Models\Cadete;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * El formulario de un cadete.
 *
 * ⚠️ Un cadete no es una fila: `cadetes.user_id` es NOT NULL y todo cadete tiene una
 * cuenta, la que usa para entrar a la app. Por eso el formulario pide también email y
 * contraseña, y por eso el alta pasa por `App\Services\AltaDeCadete`, que escribe las dos
 * cosas en una transacción. Ver `CreateCadete`.
 *
 * ⚠️ Escrito a mano. El `--generate` pone una entrada por columna, y acá eso incluye
 * `fcm_token`, la ubicación en vivo, `orden_cola` —que no es una posición sino un
 * timestamp Unix— y los cinco montos de cobranza. Los montos se editan desde las
 * pantallas de cobranza, que tienen sus propias reglas; tocarlos en un formulario de
 * datos personales es cómo se descuadra una deuda sin que nadie se entere.
 */
class CadeteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La persona')
                    ->columns(2)
                    ->components([
                        TextInput::make('nombres')
                            ->label('Nombres')
                            ->required()
                            ->maxLength(60),

                        TextInput::make('apellidos')
                            ->label('Apellidos')
                            ->required()
                            ->maxLength(60),

                        TextInput::make('dni')
                            ->label('DNI')
                            ->numeric()
                            // 7 u 8 dígitos, sin puntos ni espacios. La columna es
                            // INTEGER, así que el problema de PANEL-15 —el mismo DNI
                            // conviviendo como texto y como número— no puede volver.
                            ->rule('regex:/^\d{7,8}$/')
                            ->validationMessages([
                                'regex' => 'El DNI tiene que tener 7 u 8 dígitos, sin puntos ni espacios.',
                            ])
                            // Cuenta también a los dados de baja, porque el UNIQUE los
                            // cuenta.
                            ->unique(ignoreRecord: true)
                            ->helperText('Puede quedar vacío.'),

                        DatePicker::make('fecha_nacimiento')
                            ->label('Fecha de nacimiento')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            // ⚠️ La columna es DATE NOT NULL **sin CHECK de rango**, así
                            // que la base acepta el año 190: hay 32 cadetes con fechas
                            // imposibles, 41 personas contando las postulaciones
                            // (DATA-10). El rango se pone acá para que no entren más
                            // mientras tanto.
                            ->minDate(now()->subYears(90))
                            ->maxDate(now()->subYears(16))
                            ->helperText('Entre 16 y 90 años.'),

                        TextInput::make('telefono')
                            ->label('Teléfono')
                            ->tel()
                            ->required()
                            ->maxLength(20),

                        TextInput::make('direccion')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(255),
                    ]),

                Section::make('El móvil')
                    ->columns(2)
                    ->components([
                        TextInput::make('numero_movil')
                            ->label('N° de móvil')
                            ->numeric()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Es con lo que lo nombra la central.'),

                        Select::make('tipo_vehiculo')
                            ->label('Vehículo')
                            ->options(Cadete::VEHICULOS)
                            ->required()
                            ->native(false),

                        Select::make('estado')
                            ->label('Estado')
                            ->options(array_combine(Cadete::ESTADOS, Cadete::ESTADOS))
                            ->native(false)
                            // Al crear lo pone el servicio: nace inactivo.
                            ->visibleOn('edit')
                            // ⚠️ Cambiarlo acá cierra el tramo de actividad abierto si lo
                            // saca de la app, porque eso vive en el `saving` del modelo y
                            // no en un método que haya que acordarse de llamar. Ver
                            // PANEL-17: antes lo escribía sólo `cambiarEstado()` y el
                            // tramo quedaba abierto para siempre.
                            ->helperText('Lo normal es que lo cambie la app o la central desde la cola.'),

                        Select::make('modalidad_cobranza')
                            ->label('Modalidad de cobranza')
                            ->options([
                                Cadete::COBRANZA_SEMANAL => 'Semanal',
                                Cadete::COBRANZA_SALDO => 'Saldo',
                            ])
                            ->required()
                            ->native(false)
                            // Sin modalidad el cadete no aparece en ninguno de los dos
                            // listados de cobranzas y no hay forma de asignársela. Ver
                            // PANEL-8.
                            ->default(Cadete::COBRANZA_SEMANAL),

                        Toggle::make('tiene_monto_semanal_personal')
                            ->label('Tiene monto semanal propio')
                            ->helperText('Si no, cobra el monto general de su vehículo.'),
                    ]),

                Section::make('La cuenta de la app')
                    ->columns(2)
                    ->components([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Igual que en cuentas: se normaliza antes de validar, porque
                            // el modelo baja a minúsculas al guardar y si no lo que se
                            // valida no es lo que se escribe. Ver DATA-7.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                'email',
                                mb_strtolower(trim((string) $state)),
                            ))
                            // Contra `users`, que es donde vive el email: este
                            // campo no es una columna de `cadetes`.
                            ->unique(table: 'users', column: 'email')
                            // ⚠️ Se deshidrata como cualquier campo aunque NO sea una
                            // columna de `cadetes`: así llega a `handleRecordCreation`,
                            // que se lo pasa al servicio. El servicio arma cada fila con
                            // una lista explícita, así que un campo de más no se cuela a
                            // ninguna de las dos.
                            ->visibleOn('create'),

                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::default())
                            ->visibleOn('create')
                            // ⚠️ El panel viejo pedía un PIN numérico de 3 a 8 dígitos.
                            // Acá se pide una contraseña como la de cualquier cuenta: es
                            // la misma tabla `users` y el mismo login. Si la app necesita
                            // un PIN, eso es un mecanismo aparte y no una contraseña más
                            // corta para todos.
                            ->helperText('La misma exigencia que para cualquier cuenta.'),
                    ])
                    ->visibleOn('create')
                    ->description('Se crea junto con el cadete. Para cambiarla después, se edita la cuenta desde Usuarios.'),

                Section::make('Observaciones')
                    ->collapsed()
                    ->components([
                        Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
