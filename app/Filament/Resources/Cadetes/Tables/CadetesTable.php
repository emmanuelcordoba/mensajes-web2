<?php

namespace App\Filament\Resources\Cadetes\Tables;

use App\Models\Cadete;
use App\Services\BajaDeCadete;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * El listado de cadetes.
 *
 * Se busca por móvil, por nombre, por DNI y por teléfono. El estado y la modalidad de
 * cobranza están como filtros porque son las dos preguntas que la oficina hace: quién
 * está trabajando y a quién hay que cobrarle.
 *
 * ⚠️ La baja no es la de Filament: se lleva mensajes, postulación y cuenta, deja los
 * pedidos, y se niega si la cobranza está abierta. Ver `BajaDeCadete`.
 */
class CadetesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('numero_movil')
            ->columns([
                TextColumn::make('numero_movil')
                    ->label('Móvil')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('nombre_completo')
                    ->label('Nombre')
                    // Es un accessor, así que la búsqueda va contra las dos columnas.
                    ->searchable(['apellidos', 'nombres'])
                    ->sortable(['apellidos'])
                    ->wrap(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        in_array($state, Cadete::ESTADOS_EN_LA_APP, true) => 'success',
                        $state === Cadete::ESTADO_ACTIVO_WEB => 'info',
                        $state === Cadete::ESTADO_POSTULADO => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('tipo_vehiculo')
                    ->label('Vehículo')
                    ->formatStateUsing(fn (?string $state): string => Cadete::VEHICULOS[$state] ?? (string) $state)
                    ->toggleable(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('dni')
                    ->label('DNI')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('modalidad_cobranza')
                    ->label('Cobranza')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                // ⚠️ Los montos se muestran formateados, nunca convertidos: son
                // NUMERIC(12,2) y viajan como cadena. Castearlos a float es donde se
                // pierden los centavos de una deuda.
                TextColumn::make('monto_deuda')
                    ->label('Deuda')
                    ->money('ARS')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cobranza_saldo')
                    ->label('Saldo')
                    ->money('ARS')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('pedidos_count')
                    ->label('Entregas')
                    ->counts('pedidos')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->label('Baja')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(array_combine(Cadete::ESTADOS, Cadete::ESTADOS))
                    ->native(false),

                SelectFilter::make('modalidad_cobranza')
                    ->label('Cobranza')
                    ->options([
                        Cadete::COBRANZA_SEMANAL => 'Semanal',
                        Cadete::COBRANZA_SALDO => 'Saldo',
                    ])
                    ->native(false),

                SelectFilter::make('tipo_vehiculo')
                    ->label('Vehículo')
                    ->options(Cadete::VEHICULOS)
                    ->native(false),

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
     * La baja, con lo que se lleva y lo que deja dicho antes de hacerla.
     *
     * Como en clientes: cuando la regla dice que no, el modal explica por qué y no ofrece
     * «Confirmar». Un botón que siempre falla es peor que no tenerlo.
     */
    protected static function baja(): Action
    {
        return Action::make('baja')
            ->label('Dar de baja')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Dar de baja el cadete')
            ->modalSubmitAction(fn (Cadete $record): ?bool => BajaDeCadete::porQueNo($record) === null
                ? null
                : false)
            ->modalCancelActionLabel(fn (Cadete $record): string => BajaDeCadete::porQueNo($record) === null
                ? 'Cancelar'
                : 'Cerrar')
            ->modalDescription(function (Cadete $record): string {
                $motivo = BajaDeCadete::porQueNo($record);

                if ($motivo !== null) {
                    return $motivo;
                }

                $arrastra = BajaDeCadete::queArrastra($record);

                $seVa = ['su cuenta de la app'];

                if ($arrastra['mensajes'] > 0) {
                    $seVa[] = $arrastra['mensajes'].' mensajes';
                }

                if ($arrastra['postulacion']) {
                    $seVa[] = 'su postulación con los documentos';
                }

                $texto = 'Se dan de baja también '.implode(', ', $seVa).'.';

                // Lo que NO se va importa tanto como lo que sí: son pedidos de otros.
                if ($arrastra['pedidos'] > 0) {
                    $texto .= ' Sus '.$arrastra['pedidos'].' entregas NO se tocan: son pedidos de clientes, con su facturación.';
                }

                return $texto;
            })
            ->visible(fn (Cadete $record): bool => $record->deleted_at === null)
            ->action(function (Cadete $record): void {
                $motivo = BajaDeCadete::porQueNo($record);

                if ($motivo !== null) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede dar de baja')
                        ->body($motivo)
                        ->persistent()
                        ->send();

                    return;
                }

                (new BajaDeCadete)->dar($record, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Cadete dado de baja')
                    ->body('Sus entregas quedaron donde estaban.')
                    ->send();
            });
    }
}
