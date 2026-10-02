<?php

namespace App\Filament\Resources\Cadetes\Pages;

use App\Filament\Resources\Cadetes\CadeteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCadete extends EditRecord
{
    protected static string $resource = CadeteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
