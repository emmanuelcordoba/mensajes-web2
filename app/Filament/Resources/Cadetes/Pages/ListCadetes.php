<?php

namespace App\Filament\Resources\Cadetes\Pages;

use App\Filament\Resources\Cadetes\CadeteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCadetes extends ListRecords
{
    protected static string $resource = CadeteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
