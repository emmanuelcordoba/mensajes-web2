<?php

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * El formulario de un cliente.
 *
 * Los cuatro campos que editaba el panel viejo —número, nombre, dirección y teléfono—
 * más lo que la app escribe, que acá se muestra y no se toca.
 *
 * ⚠️ Escrito a mano, como el de cuentas. El `--generate` pone una entrada por columna, y
 * entre ellas `fcm_token`: el token de notificaciones del teléfono de esa persona. No es
 * un dato que se edite, y a la vista es con lo que se le mandan notificaciones a un
 * dispositivo ajeno. Tampoco tiene sentido `nombre_mostrado`, que PostgreSQL calcula y
 * rechaza si se le escribe.
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

                Section::make('Lo que escribe la app')
                    ->columns(2)
                    ->collapsed()
                    ->description('Viene de la aplicación del cliente. Se muestra para poder mirarlo, no se edita desde acá.')
                    ->components([
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

                        TextInput::make('nombres')
                            ->label('Nombres')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('apellidos')
                            ->label('Apellidos')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('user.email')
                            ->label('Cuenta de la app')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Sin cuenta')
                            // ⚠️ No es un select. Mover un cliente de una cuenta a otra es
                            // lo que hace FusionDeClientes en el sistema viejo, con sus
                            // reglas —un cliente no puede colgar de dos cuentas—, y no un
                            // desplegable sin guardas. Acá se muestra para saber de quién
                            // es.
                            ->helperText('Para moverlo de cuenta hace falta la pantalla de fusión, que todavía no está.'),
                    ]),
            ]);
    }
}
