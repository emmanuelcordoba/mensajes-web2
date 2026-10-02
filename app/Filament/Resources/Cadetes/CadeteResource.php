<?php

namespace App\Filament\Resources\Cadetes;

use App\Filament\Resources\Cadetes\Pages\CreateCadete;
use App\Filament\Resources\Cadetes\Pages\EditCadete;
use App\Filament\Resources\Cadetes\Pages\ListCadetes;
use App\Filament\Resources\Cadetes\Schemas\CadeteForm;
use App\Filament\Resources\Cadetes\Tables\CadetesTable;
use App\Models\Cadete;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Los cadetes, desde /admin.
 *
 * Es la pantalla de Cadetes del panel viejo: alta, edición y baja, sobre los 1.768 de
 * producción. Es la tabla con más restricciones del esquema porque era la que tenía más
 * datos sucios: `numero_movil` y `dni` UNIQUE incluyendo a los dados de baja, `estado` y
 * `tipo_vehiculo` con CHECK, y `modalidad_cobranza` NOT NULL.
 *
 * ⚠️ El alta escribe DOS filas —la cuenta y el cadete—, en una transacción. Ver
 * `App\Services\AltaDeCadete` y la página `CreateCadete`.
 *
 * ⚠️ La baja se niega si la cobranza está abierta, y deja los pedidos donde están. Ver
 * `App\Services\BajaDeCadete`.
 *
 * Lo que NO está acá, a propósito:
 *
 * - **Las cobranzas**, semanal y por saldo. Son dos pantallas con sus propias reglas y
 *   plata de por medio; los montos acá se miran y no se editan.
 * - **La cola**: qué cadete está primero lo decide la operación en vivo, no un ABM.
 * - **Las postulaciones**, que son el legajo previo a contratar y tienen su propio
 *   circuito con documentos.
 * - **El borrado definitivo** del sistema viejo, que es de DATA-6: va sólo sobre cadetes
 *   ya dados de baja, exige escribir el número de móvil para confirmar y deja registro.
 *   Es una pantalla propia, no un botón de listado.
 */
class CadeteResource extends Resource
{
    protected static ?string $model = Cadete::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'numero_movil';

    public static function getNavigationLabel(): string
    {
        return 'Cadetes';
    }

    public static function getModelLabel(): string
    {
        return 'cadete';
    }

    public static function getPluralModelLabel(): string
    {
        return 'cadetes';
    }

    public static function form(Schema $schema): Schema
    {
        return CadeteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CadetesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCadetes::route('/'),
            'create' => CreateCadete::route('/create'),
            'edit' => EditCadete::route('/{record}/edit'),
        ];
    }

    /** Para poder abrir y restaurar uno dado de baja. */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
