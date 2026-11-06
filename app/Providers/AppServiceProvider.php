<?php

namespace App\Providers;

use App\Domain\Security\Backup\BackupCipher;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Lazy on purpose: the key is read when a backup command runs, not when a request boots. A
        // deployment that has not yet escrowed FLEET_BACKUP_KEY must still serve every other screen,
        // and the export is where a missing key has to be loud.
        $this->app->bind(BackupCipher::class, static fn (): BackupCipher => BackupCipher::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureKioskLimiter();
    }

    /**
     * The rate limit on redemption.
     *
     * Keyed by IP *and* the secret rather than IP alone: a yard on one NAT address dispatches many
     * drivers at once, and a shared counter would lock the whole office out because one phone kept
     * retrying. The named limiter lives here so the route carries it visibly (`throttle:kiosk`) instead
     * of a component remembering to count.
     */
    protected function configureKioskLimiter(): void
    {
        RateLimiter::for('kiosk', function (Request $request): Limit {
            return Limit::perMinute((int) config('fleet.kiosk.pin_attempts_per_minute', 5))
                ->by((string) $request->ip().'|'.(string) $request->route('secret'));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        /*
         * The N+1 guard: outside production a missing eager load throws instead of quietly
         * costing a query per row. Off in production by design — an uncaught violation there would
         * be an outage, not a slower page, and the local sweeps and `performance:audit` are where the
         * rule is enforced.
         */
        Model::preventLazyLoading(! app()->isProduction());

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
