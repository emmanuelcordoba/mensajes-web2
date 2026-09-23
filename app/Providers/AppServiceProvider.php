<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiTokens();
        $this->configureSchema();
    }

    /**
     * Keep PostgreSQL's microsecond precision on dates and times, as ESQUEMA.sql
     * defines them. Laravel defaults to whole seconds, and a TIMESTAMPTZ(0)
     * column rounds the milliseconds of the migrated MongoDB dates: 23:59:59.6
     * on the last day of a month would move to the next month.
     */
    protected function configureSchema(): void
    {
        Schema::defaultTimePrecision(null);
    }

    /**
     * Make API tokens expire by inactivity. See config/sanctum.php.
     */
    protected function configureApiTokens(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
