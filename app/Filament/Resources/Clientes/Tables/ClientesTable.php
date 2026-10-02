<?php

namespace App\Filament\Resources\Clientes\Tables;

use App\Models\Cliente;
use App\Services\BajaDeCliente;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El listado de clientes.
 *
 * Son 11.057, así que lo que importa es encontrar uno: se busca por número, por nombre y
 * por teléfono, y el filtro de origen separa los que vinieron de la app de los que cargó
 * la oficina, que es el mismo corte que ofrecía el listado viejo.
 *
 * ⚠️ La baja no es la de Filament. Dar de baja un cliente se lleva puestos sus pedidos y
 * su cuenta de la app, y hay un caso en que no se puede hacer: ver `BajaDeCliente`. El
 * botón pregunta la misma regla que el servicio aplica, y dice de antemano cuántos
 * pedidos se va a llevar.
 */
class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('numero', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('N°')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('nombre_mostrado')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('direccion')
                    ->label('Dirección')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable(),

                TextColumn::make('plataforma')
                    ->label('Origen')
                    ->badge()
                    ->placeholder('Panel')
                    ->formatStateUsing(fn (?string $state): string => $state === Cliente::PLATAFORMA_APP
                        ? 'App'
                        : (string) $state),

                TextColumn::make('pedidos_count')
                    ->label('Pedidos')
                    ->counts('pedidos')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('user.email')
                    ->label('Cuenta')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->label('Baja')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('plataforma')
                    ->label('Origen')
                    ->options([
                        Cliente::PLATAFORMA_APP => 'App',
                        'panel' => 'Panel',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            Cliente::PLATAFORMA_APP => $query->where('plataforma', Cliente::PLATAFORMA_APP),
                            // Los cargados desde el panel tienen la columna en NULL.
                            'panel' => $query->whereNull('plataforma'),
                            default => $query,
                        };
                    }),

                TernaryFilter::make('user_id')
                    ->label('Cuenta de la app')
                    ->nullable()
                    ->placeholder('Todos')
                    ->trueLabel('Sólo con cuenta')
                    ->falseLabel('Sólo sin cuenta'),

                TrashedFilter::make()
                    ->label('Dados de baja'),
            ])
            ->recordActions([
                EditAction::make(),
                self::baja(),
                RestoreAction::make(),
            ]);
    }

    /**
     * La baja, con lo que arrastra dicho antes de hacerla.
     *
     * No es `DeleteAction`: ésa da de baja la fila y nada más, y dejaría los pedidos
     * colgando de un cliente que ya no está. Ver `BajaDeCliente`.
     */
    protected static function baja(): Action
    {
        return Action::make('baja')
            ->label('Dar de baja')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Dar de baja el cliente')
            // ⚠️ Cuando la regla dice que no, el modal explica por qué y NO ofrece
            // «Confirmar». Un botón que siempre falla es peor que no tenerlo: la primera
            // versión de esto lo dejaba puesto, y apretarlo sólo sacaba un aviso de
            // error. Es el mismo problema que la pantalla de emails repetidos del
            // sistema viejo, donde el listado ofrecía una fusión que el servicio negaba.
            ->modalSubmitAction(fn (Cliente $record): ?bool => BajaDeCliente::porQueNo($record) === null
                ? null
                : false)
            ->modalCancelActionLabel(fn (Cliente $record): string => BajaDeCliente::porQueNo($record) === null
                ? 'Cancelar'
                : 'Cerrar')
            ->modalDescription(function (Cliente $record): string {
                $motivo = BajaDeCliente::porQueNo($record);

                if ($motivo !== null) {
                    return $motivo;
                }

                $arrastra = BajaDeCliente::queArrastra($record);

                $texto = 'Se dan de baja también sus '.$arrastra['pedidos'].' pedidos';
                $texto .= $arrastra['cuenta'] !== null
                    ? ' y su cuenta de la app ('.$arrastra['cuenta'].').'
                    : '.';

                return $texto.' Se puede restaurar el cliente, pero los pedidos quedan dados de baja.';
            })
            // La misma pregunta que hace el servicio antes de escribir: si no se puede,
            // el botón no se dibuja. Ver el docblock de BajaDeCliente.
            ->visible(fn (Cliente $record): bool => $record->deleted_at === null)
            ->action(function (Cliente $record): void {
                $motivo = BajaDeCliente::porQueNo($record);

                if ($motivo !== null) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede dar de baja')
                        ->body($motivo)
                        ->persistent()
                        ->send();

                    return;
                }

                $llevo = (new BajaDeCliente)->dar($record, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Cliente dado de baja')
                    ->body('Con sus '.$llevo['pedidos'].' pedidos.')
                    ->send();
            });
    }
}
