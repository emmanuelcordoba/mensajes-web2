<?php

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use App\Models\Cliente;
use App\Services\CuentaDeCliente;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Editar un cliente, y con él la cuenta con la que entra a la app.
 *
 * ⚠️ El email y la contraseña NO son columnas de `clientes`: el formulario los manda como
 * `cuenta_email` y `cuenta_password`, acá se sacan de los datos del cliente y los escribe
 * `CuentaDeCliente`. **Si no se sacaran, el `Fillable` del modelo los ignoraría en
 * silencio** y guardar parecería funcionar sin cambiar nada.
 */
class EditCliente extends EditRecord
{
    protected static string $resource = ClienteResource::class;

    /**
     * Qué se cambió de la cuenta, para poder decirlo al guardar.
     *
     * @var array{email: bool, contrasena: bool, tokens: int, sesiones: int}|null
     */
    protected ?array $cambiosEnLaCuenta = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * El email no sale de `clientes`, así que hay que ir a buscarlo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $cliente = $this->getRecord();

        $data['cuenta_email'] = $cliente instanceof Cliente ? $cliente->user?->email : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $email = $data['cuenta_email'] ?? null;
        $contrasena = $data['cuenta_password'] ?? null;

        unset($data['cuenta_email'], $data['cuenta_password'], $data['cuenta_password_confirmation']);

        // Las dos filas van juntas o no van: si la cuenta falla —un email que entró en uso
        // entre la validación y el guardado— el cliente no queda escrito a medias.
        DB::transaction(function () use ($record, $data, $email, $contrasena): void {
            $record->update($data);

            if ($record instanceof Cliente && CuentaDeCliente::porQueNo($record) === null) {
                $this->cambiosEnLaCuenta = app(CuentaDeCliente::class)
                    ->actualizar($record, is_string($email) ? $email : null, is_string($contrasena) ? $contrasena : null);
            }
        });

        // ⚠️ `nombre_mostrado` lo calcula PostgreSQL y Eloquent no lo vuelve a leer: sin
        // esto el campo gris sigue mostrando el nombre viejo después de guardar, y parece
        // que el cambio no entró.
        $record->refresh();

        return $record;
    }

    /**
     * Al guardar se dice qué pasó con la cuenta, porque no es obvio.
     *
     * Que cambiar una contraseña cierre la sesión de la app es correcto y es lo que se
     * decidió, pero quien lo hizo tiene que enterarse ahí mismo: la persona del otro lado
     * va a tener que volver a entrar, y si además cambió el email, a verificarlo.
     */
    protected function getSavedNotification(): ?Notification
    {
        $cambios = $this->cambiosEnLaCuenta;

        if ($cambios === null || (! $cambios['email'] && ! $cambios['contrasena'])) {
            return parent::getSavedNotification();
        }

        $lineas = [];

        if ($cambios['email']) {
            $lineas[] = 'El email quedó sin verificar: la app le va a pedir el código nuevo '
                .'a la casilla nueva antes de dejarla entrar.';
        }

        if ($cambios['contrasena']) {
            $lineas[] = match (true) {
                $cambios['tokens'] > 0 => 'Contraseña cambiada, y se cerró la app en '
                    .$cambios['tokens'].($cambios['tokens'] === 1 ? ' dispositivo.' : ' dispositivos.'),
                default => 'Contraseña cambiada. No tenía la app abierta en ningún dispositivo.',
            };
        }

        return Notification::make()
            ->success()
            ->title('Cliente guardado')
            ->body(implode(' ', $lineas))
            ->persistent();
    }
}
