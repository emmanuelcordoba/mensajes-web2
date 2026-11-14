<?php

namespace App\Filament\Pages;

use App\Services\FallasDeLaApi;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Lo que falló, mirado desde /admin.
 *
 * ⚠️ **Es el complemento de Telescope, no su reemplazo.** Telescope guarda cada pedido
 * fallido entero —el cuerpo, las cabeceras, la traza— y lo muestra de a uno; esta pantalla
 * los cuenta. Son las dos mitades de la misma pregunta: Telescope contesta «qué pasó en
 * este pedido» y esto contesta «cuántos, de qué código, de qué ruta y de qué móvil», que
 * es lo que hay que saber ANTES de elegir cuál abrir.
 *
 * ⚠️ Y es lo que el monitoreo de Laravel Cloud no da: allá se ve «187 respuestas 4XX en
 * /api/actualizarubicacion» y nada más, y sólo del último día. Acá son treinta días —lo
 * que deja la poda— y con el móvil adelante.
 *
 * El permiso ya está puesto y no se agrega nada acá: a /admin entra sólo `admin`, por
 * `User::canAccessPanel()`. Ver `AdminPanelProvider`.
 *
 * @phpstan-import-type PorCodigo from FallasDeLaApi
 * @phpstan-import-type PorRuta from FallasDeLaApi
 * @phpstan-import-type PorMovil from FallasDeLaApi
 * @phpstan-import-type PorDia from FallasDeLaApi
 * @phpstan-import-type Excepcion from FallasDeLaApi
 * @phpstan-import-type Falla from FallasDeLaApi
 */
class Fallas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $title = 'Fallas de la API';

    protected static ?string $navigationLabel = 'Fallas';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.fallas';

    /** Cuántos días se están mirando. Uno es lo que deja ver Cloud; acá se puede más. */
    public int $dias = 7;

    /** @return list<int> */
    public function diasQueSePuedenMirar(): array
    {
        return FallasDeLaApi::DIAS;
    }

    public function estaPrendido(): bool
    {
        return $this->fallas()->estaPrendido();
    }

    public function total(): int
    {
        return $this->fallas()->total($this->dias);
    }

    /** @return Collection<int, PorCodigo> */
    public function porCodigo(): Collection
    {
        return $this->fallas()->porCodigo($this->dias);
    }

    /** @return Collection<int, PorRuta> */
    public function porRuta(): Collection
    {
        return $this->fallas()->porRuta($this->dias);
    }

    /** @return Collection<int, PorMovil> */
    public function porMovil(): Collection
    {
        return $this->fallas()->porMovil($this->dias);
    }

    /** @return Collection<int, PorDia> */
    public function porDia(): Collection
    {
        return $this->fallas()->porDia($this->dias);
    }

    /** @return Collection<int, Excepcion> */
    public function excepciones(): Collection
    {
        return $this->fallas()->excepciones($this->dias);
    }

    /** @return Collection<int, Falla> */
    public function ultimas(): Collection
    {
        return $this->fallas()->ultimas($this->dias);
    }

    /**
     * De qué color se lee un código.
     *
     * No es decoración: un 5XX es nuestro y un 4XX casi siempre es de quien llama, y esa
     * diferencia es la que decide quién tiene que arreglar algo.
     */
    public function colorDe(int $codigo): string
    {
        return $codigo >= 500 ? 'danger' : 'warning';
    }

    private function fallas(): FallasDeLaApi
    {
        return app(FallasDeLaApi::class);
    }
}
