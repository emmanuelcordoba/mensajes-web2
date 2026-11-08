<?php

namespace App\Filament\Pages;

use App\Models\Pedido;
use App\Services\EstadoDeLaCola;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Collection;

/**
 * La cola, mirada desde /admin.
 *
 * ⚠️ **No reemplaza a un worker administrado, avisa cuando no hay uno.** El día del corte
 * el worker corría colgado de una consola y se caía con cada despliegue; esta pantalla no
 * habría evitado nada, pero el «más viejo esperando» habría pasado de segundos a una hora
 * a la vista de todos, en lugar de descubrirse consultando la base.
 *
 * El permiso ya está puesto y no se agrega nada acá: a /admin entra sólo `admin`, por
 * `User::canAccessPanel()`. Ver `AdminPanelProvider`.
 *
 * ⚠️ La pantalla se refresca sola cada diez segundos —`wire:poll` en la vista—, así que
 * sirve para mirarla mientras se asigna un pedido del otro lado y ver el reloj aparecer.
 *
 * @phpstan-import-type TrabajoEnCola from EstadoDeLaCola
 * @phpstan-import-type TrabajoFallado from EstadoDeLaCola
 */
class Cola extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $title = 'Cola de trabajos';

    protected static ?string $navigationLabel = 'Cola';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.cola';

    /**
     * Los fallados, en el menú.
     *
     * Son lo único que pide que alguien haga algo: un pendiente se resuelve solo en cuanto
     * un worker lo tome.
     */
    public static function getNavigationBadge(): ?string
    {
        $fallados = app(EstadoDeLaCola::class)->resumen()['fallados'];

        return $fallados > 0 ? (string) $fallados : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    /** @return array{pendientes: int, enCurso: int, fallados: int, segundosDelMasViejo: int|null} */
    public function resumen(): array
    {
        return $this->estado()->resumen();
    }

    /** @return Collection<int, TrabajoEnCola> */
    public function pendientes(): Collection
    {
        return $this->estado()->pendientes();
    }

    /** @return Collection<int, TrabajoFallado> */
    public function fallados(): Collection
    {
        return $this->estado()->fallados();
    }

    /** @return ColeccionDeModelos<int, Pedido> */
    public function pedidosSinReloj(): ColeccionDeModelos
    {
        return $this->estado()->pedidosSinReloj();
    }

    public function reintentar(string $uuid): void
    {
        $this->estado()->reintentar($uuid);

        Notification::make()
            ->title('Encolado de nuevo')
            ->body('Lo va a tomar el próximo worker que pase.')
            ->success()
            ->send();
    }

    public function olvidar(string $uuid): void
    {
        $this->estado()->olvidar($uuid);

        Notification::make()
            ->title('Borrado de la lista de fallados')
            ->body('No se reintentó: el trabajo no se hizo.')
            ->warning()
            ->send();
    }

    private function estado(): EstadoDeLaCola
    {
        return app(EstadoDeLaCola::class);
    }
}
