<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\Rol;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El listado de cuentas.
 *
 * ⚠️ Tampoco es el que generó Filament. El `--generate` hace una columna por cada
 * columna de la tabla, así que ponía `codigo_de_verificacion` a la vista de todos —el
 * código con el que se le cambia la contraseña a cualquiera, SEC-10— y además el
 * segundo factor. Acá están las columnas que sirven para encontrar a alguien.
 *
 * El filtro de cuentas del panel viene puesto, como el listado viejo, que mostraba sólo
 * empleado, admin, restringido y cliente_api. La diferencia es que acá se puede sacar:
 * las cuentas de cadete y de la app son 9.174 y antes no había forma de verlas.
 */
class UsersTable
{
    /** Los roles que usan el panel o la API; los otros dos son de las aplicaciones. */
    protected const DEL_PANEL = [Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO, 'cliente_api'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('rol.display_rol')
                    ->label('Rol')
                    ->badge()
                    ->placeholder('Sin rol')
                    ->sortable(),

                TextColumn::make('clienteRestringido.nombre_mostrado')
                    ->label('Comercio')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('motivoBloqueo.descripcion')
                    ->label('Bloqueo')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),

                IconColumn::make('email_verified_at')
                    ->label('Verificado')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->label('Dada de baja')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('del_panel')
                    ->label('Sólo cuentas del panel')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'rol',
                        fn (Builder $rol): Builder => $rol->whereIn('rol', self::DEL_PANEL),
                    )),

                SelectFilter::make('rol_id')
                    ->label('Rol')
                    ->relationship('rol', 'display_rol')
                    ->native(false),

                TernaryFilter::make('bloqueado')
                    ->label('Bloqueadas')
                    ->nullable()
                    ->placeholder('Todas')
                    ->trueLabel('Sólo bloqueadas')
                    ->falseLabel('Sólo sin bloquear'),

                TrashedFilter::make()
                    ->label('Dadas de baja'),
            ])
            ->recordActions([
                EditAction::make(),
                // ⚠️ Baja lógica, y no hay borrado definitivo a propósito. Seis tablas
                // apuntan a `users` con NO ACTION —pedidos, mensajes, logs de estado,
                // movimientos de cobranza, clientes y cadetes—, así que borrar de verdad
                // una cuenta con historia es un error de clave foránea, no un botón. En
                // el sistema viejo eso lo hace FusionDeUsuarios, que primero mueve lo que
                // cuelga. Si alguna vez hace falta acá, es una pantalla propia y no una
                // acción de listado.
                DeleteAction::make()
                    // Nadie se da de baja a sí mismo: el siguiente clic sería contra una
                    // cuenta que ya no entra.
                    ->visible(fn (User $record): bool => $record->id !== auth()->id()),
                RestoreAction::make(),
            ]);
    }
}
