<?php

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Resources\Clientes\Tables\ClientesTable;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Los clientes, desde /admin.
 *
 * Es la pantalla de Clientes del panel viejo: alta, edición y baja. Son 11.057, de los
 * cuales 7.139 vinieron de la app y el resto los cargó la oficina.
 *
 * ⚠️ La baja no es la de Filament: se lleva puestos los pedidos del cliente y su cuenta
 * de la app, y hay un caso en que se niega. Toda esa regla está en
 * `App\Services\BajaDeCliente`, que es donde se puede probar, y no adentro de un botón.
 *
 * Lo que NO está acá, a propósito:
 *
 * - **Mover un cliente de una cuenta a otra.** Es lo que hace `FusionDeClientes` en el
 *   sistema viejo, con sus reglas —un cliente no puede colgar de dos cuentas—, y un
 *   desplegable sin esas guardas es peor que no tenerlo.
 * - **Los listados de corrección** —números repetidos, nombres repetidos, nombres a
 *   revisar—. Son de DATA-6 y DATA-8: existen para arreglar lo que el esquema nuevo
 *   rechaza, y acá no tendrían nada que listar.
 * - **La exportación a Excel** que tiene el panel viejo (`clientes.export`).
 */
class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'nombre_mostrado';

    public static function getNavigationLabel(): string
    {
        return 'Clientes';
    }

    public static function getModelLabel(): string
    {
        return 'cliente';
    }

    public static function getPluralModelLabel(): string
    {
        return 'clientes';
    }

    public static function form(Schema $schema): Schema
    {
        return ClienteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientesTable::configure($table);
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
            'index' => ListClientes::route('/'),
            'create' => CreateCliente::route('/create'),
            'edit' => EditCliente::route('/{record}/edit'),
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
