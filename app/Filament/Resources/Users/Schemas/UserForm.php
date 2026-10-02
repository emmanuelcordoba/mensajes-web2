<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Cliente;
use App\Models\MotivoBloqueo;
use App\Models\Rol;
use App\Models\User;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * El formulario de una cuenta.
 *
 * ⚠️ ESTÁ ESCRITO A MANO Y NO ES EL QUE GENERÓ FILAMENT. El `--generate` arma el
 * formulario leyendo las columnas de la tabla, y por eso dejó puestos
 * `codigo_de_verificacion`, `two_factor_secret` y `two_factor_recovery_codes`: tres
 * campos que no se muestran ni se editan nunca. Con el código de verificación se le
 * cambia la contraseña a cualquiera —es SEC-10, por eso está en `#[Hidden]` del
 * modelo—, y los otros dos son el segundo factor de la persona. Una plantilla no sabe
 * eso; vuelve a aparecer cada vez que alguien corra el generador de nuevo.
 *
 * Lo que sí está es lo que el panel viejo editaba —nombre, email, contraseña, rol,
 * iniciales y color— más lo que el esquema nuevo agregó: el comercio de una cuenta
 * `restringido` (PANEL-3) y el motivo de bloqueo tomado de un catálogo (PANEL-12).
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La cuenta')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // ⚠️ Se normaliza al salir del campo, antes de validar, y no
                            // al guardar. El modelo baja a minúsculas y recorta en su
                            // mutador, así que si acá se validara «Ana@X.com» tal cual,
                            // `unique` no encontraría la fila guardada como «ana@x.com»,
                            // pasaría la validación y reventaría contra el índice único
                            // al grabar. Normalizar primero hace que lo que se valida sea
                            // lo mismo que se guarda. De ahí salieron los 77 grupos de
                            // emails repetidos de producción: ver DATA-7.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                'email',
                                mb_strtolower(trim((string) $state)),
                            ))
                            // Cuenta también las cuentas dadas de baja, porque el índice
                            // único las cuenta: una cuenta borrada sigue ocupando su
                            // email. Es la mitad de DATA-7 que no era obvia.
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->same('password_confirmation')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // Al editar, en blanco significa «no la toques». Sin esto, un
                            // cambio de nombre le borraría la contraseña a la persona.
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Dejala en blanco para no cambiarla.'
                                : null),

                        TextInput::make('password_confirmation')
                            ->label('Repetir contraseña')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // No es una columna: sólo existe para comparar.
                            ->dehydrated(false),
                    ]),

                Section::make('Qué puede hacer')
                    ->columns(2)
                    ->components([
                        Select::make('rol_id')
                            ->label('Rol')
                            ->relationship('rol', 'display_rol')
                            ->required()
                            ->native(false)
                            // ⚠️ `live` porque de este campo cuelga el del comercio. Sin
                            // esto el cambio no vuelve al servidor y el campo dependiente
                            // no aparece nunca: se elige «Restringido» y no pasa nada.
                            // Los tests NO lo ven —`fillForm()` vuelve a armar el esquema
                            // igual—, lo encontró abrir la pantalla.
                            ->live()
                            // ⚠️ Nadie se cambia el rol a sí mismo. Un administrador que
                            // se baja el rol pierde /admin en el mismo clic, y lo que
                            // queda es entrar a la base a mano. Deshabilitado no se
                            // manda, así que el valor queda como está.
                            ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                            ->helperText(fn (?User $record): ?string => $record?->id === auth()->id()
                                ? 'Es tu propia cuenta: el rol lo tiene que cambiar otro administrador.'
                                : null),

                        Select::make('cliente_restringido_id')
                            ->label('Comercio que ve')
                            ->relationship('clienteRestringido', 'nombre_mostrado')
                            // ⚠️ Con el número además del nombre: hay nombres repetidos
                            // entre los clientes, y un select con tres «Dasani SAS» no
                            // dice a cuál se está apuntando. Mismo problema que tuvo la
                            // pantalla de emails repetidos del sistema viejo.
                            ->getOptionLabelFromRecordUsing(
                                fn (Cliente $record): string => "N° {$record->numero} · {$record->nombre_mostrado}"
                            )
                            // Sin preload: son más de nueve mil clientes.
                            ->searchable(['numero', 'nombre_mostrado'])
                            ->preload(false)
                            // Sólo significa algo para un comercio. Para los demás roles
                            // la columna queda en null, que es lo que corresponde.
                            ->visible(fn (Get $get): bool => self::esComercio($get('rol_id')))
                            ->required(fn (Get $get): bool => self::esComercio($get('rol_id')))
                            ->helperText('Los pedidos que esta cuenta puede ver son los de este comercio.'),
                    ]),

                Section::make('Bloqueo')
                    ->columns(2)
                    ->collapsed(fn (?User $record): bool => ! $record?->estaBloqueado())
                    ->components([
                        Select::make('bloqueado')
                            ->label('Motivo')
                            // Un catálogo cerrado y no texto libre: en el sistema viejo el
                            // motivo se escribía a mano y entraba cualquier cosa. Ver
                            // PANEL-12.
                            ->options(fn (): array => MotivoBloqueo::query()
                                ->pluck('descripcion', 'codigo')
                                ->all())
                            ->native(false)
                            ->placeholder('Sin bloquear')
                            ->live(),

                        Textarea::make('bloqueado_mensaje')
                            ->label('Qué se le muestra')
                            ->rows(2)
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => filled($get('bloqueado')))
                            ->helperText('Lo lee la persona cuando intenta entrar.'),
                    ]),

                Section::make('Cómo se ve')
                    ->columns(3)
                    ->collapsed()
                    ->components([
                        TextInput::make('iniciales')
                            ->label('Iniciales')
                            ->maxLength(2)
                            ->helperText('Dos letras, para el avatar.'),

                        ColorPicker::make('color')
                            ->label('Color'),

                        DateTimePicker::make('email_verified_at')
                            ->label('Email verificado el')
                            ->seconds(false)
                            // ⚠️ Hoy no decide nada: `User` no implementa MustVerifyEmail,
                            // así que el middleware `verified` no mira esta columna. Se
                            // edita igual porque el dato es real y el día que se encienda
                            // va a hacer falta poder arreglarlo a mano.
                            ->helperText('Hoy la aplicación no exige verificación.'),
                    ]),
            ]);
    }

    /** Si el rol elegido es el de un comercio, que es el único que mira un cliente. */
    protected static function esComercio(mixed $rolId): bool
    {
        if (blank($rolId)) {
            return false;
        }

        return Rol::query()->whereKey($rolId)->value('rol') === Rol::RESTRINGIDO;
    }
}
