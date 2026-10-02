<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Las cuentas, desde /admin.
 *
 * Es el reemplazo de la pantalla de Usuarios del panel viejo —`users.index` y
 * `users.create`—, con lo mismo que editaba aquélla más lo que el esquema nuevo agregó.
 *
 * ⚠️ Quién llega acá lo decide `User::canAccessPanel()`, que sólo deja entrar a `admin`.
 * No hay una política por recurso encima: todo el panel es de administradores. Si alguna
 * vez entra otro rol a /admin, esta pantalla necesita una UserPolicy antes, porque desde
 * acá se cambian roles y contraseñas de cualquiera.
 *
 * ⚠️ `User::puedeAdministrarA()` —SEC-9, la cuenta de un admin sólo la toca otro admin—
 * queda satisfecha sola mientras /admin sea de admins, pero no está comprobada acá. Es
 * la otra razón por la que abrir /admin a `empleado` no es sólo cambiar una línea.
 *
 * Lo que NO está, y es a propósito: el token de integración de las cuentas `cliente_api`.
 * En el sistema viejo se emite al crear la cuenta y se muestra una sola vez (SEC-5), y
 * se puede regenerar. Acá la API todavía no está portada, así que emitir un token sería
 * emitirlo contra nada. Va cuando vaya la API.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'Usuarios';
    }

    public static function getModelLabel(): string
    {
        return 'cuenta';
    }

    public static function getPluralModelLabel(): string
    {
        return 'cuentas';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Para poder abrir una cuenta dada de baja y restaurarla: sin esto el binding de la
     * ruta no la encuentra y el listado ofrece un botón que lleva a un 404.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
