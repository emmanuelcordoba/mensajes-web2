<?php

namespace App\Filament\Resources\Cadetes\Pages;

use App\Filament\Resources\Cadetes\CadeteResource;
use App\Services\AltaDeCadete;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * El alta de un cadete.
 *
 * ⚠️ No usa el alta de Filament, que escribe una fila y nada más. `cadetes.user_id` es
 * NOT NULL: todo cadete nace con la cuenta que usa para entrar a la app, así que son dos
 * escrituras y tienen que ir en una transacción. Eso vive en `App\Services\AltaDeCadete`,
 * donde se puede probar sin montar un formulario.
 *
 * `email` y `password` llegan en `$data` como cualquier otro campo y no son columnas de
 * `cadetes`; el servicio lee lo que necesita de cada lado y arma las dos filas.
 */
class CreateCadete extends CreateRecord
{
    protected static string $resource = CadeteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return (new AltaDeCadete)->alta($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
