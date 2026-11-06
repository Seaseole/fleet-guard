<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Actions\ImportOpeningLoans;
use App\Domain\Accounting\Actions\ImportOpeningPayables;
use App\Domain\Accounting\Actions\ImportSuppliers;
use App\Domain\Accounting\Alerts\LoanDueSource;
use App\Domain\Fleet\Actions\ImportOpeningOdometerReadings;
use App\Domain\Fleet\Actions\ImportTrailers;
use App\Domain\Fleet\Actions\ImportTrucks;
use App\Domain\Fuel\Actions\ImportOpeningTankStock;
use App\Domain\Invoicing\Actions\ImportCustomers;
use App\Domain\Invoicing\Actions\ImportOpeningReceivables;
use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Sources\DocumentExpirySource;
use App\Domain\Kernel\Alerts\Support\AlertDelivery;
use App\Domain\Kernel\Alerts\Support\ReminderRunner;
use App\Domain\Kernel\Alerts\Support\ThresholdRepository;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverHandlers;
use App\Domain\Kernel\Documents\Scan\ClamAvScanner;
use App\Domain\Kernel\Documents\Scan\NoScanner;
use App\Domain\Kernel\Documents\Scan\VirusScanner;
use App\Domain\Kernel\Money\RateResolver;
use App\Domain\Kernel\Money\StoredRateResolver;
use App\Domain\Kernel\Settings\CachedSettings;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\ImportParts;
use App\Domain\Maintenance\Alerts\ServiceDueSource;
use App\Domain\Operations\Actions\ImportRates;
use App\Domain\Operations\Actions\ImportRoutes;
use App\Domain\People\Actions\ImportDrivers;
use App\Domain\People\Actions\ImportEmployees;
use App\Domain\People\Actions\ImportOpeningDriverAdvances;
use App\Domain\People\Alerts\DriverPaperExpirySource;
use App\Domain\Security\Sessions\EndSessionOnLogout;
use Egough\LaravelSettings\Contracts\SettingsRepository;
use Illuminate\Auth\Events\Logout;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings and lifecycle hooks for the Kernel's swappable edges. One provider rather than
 * scattered `bind()` calls: The decision is that a rate feed, a GPS adapter or a PDF
 * driver becomes an adapter behind an existing interface, and this is the single place that
 * decides which one.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RateResolver::class, StoredRateResolver::class);

        // /T4: settings are company-held, so the package's storage seam is replaced with the
        // one over `companies.settings`. `settings()`, its Blade directives and its console
        // commands then speak for the current company instead of a tenant-less table. This is
        // the package's documented extension point, not a fork.
        //
        // Scoped, not singleton: the memo inside `CachedSettings` must not survive the request
        // (or the queued job) that filled it — a worker holds the container for the life of the
        // process, and a settings write on one job would otherwise be read stale by the next.
        $this->app->scoped(CachedSettings::class);
        $this->app->scoped(CompanySettings::class);
        $this->app->alias(CompanySettings::class, SettingsRepository::class);

        // The package answers keys it cannot find from `settings.defaults`. The app's defaults are
        // `config/fleet.php`, so they are mirrored there once: `settings()->get()` and
        // `CompanySettings::get()` must not be able to disagree about a starting value.
        config(['settings.defaults' => array_merge(
            (array) config('settings.defaults', []),
            CachedSettings::flatten((array) config('fleet', [])),
        )]);

        // The master-data datasets, mapped to the module that owns each one. This is the seam
        // that keeps D2 true: the engine under `app/Domain/Kernel` asks for a dataset handler and
        // never learns which module answers, while *this* file — the one the architecture lint
        // allows to name modules — is where the names live. The two opening party-detail datasets
        // belong to the modules whose documents they create, not to the cutover engine: an
        // open receivable is an invoice, and Invoicing is the only door allowed to write one. The
        // 8c pair follows the same rule by the same route: an unreconciled advance is a
        // driver's receivable, so People answers it, and a facility is a loan row, so Accounting does.
        $this->app->singleton(CutoverHandlers::class, fn (): CutoverHandlers => new CutoverHandlers($this->app, [
            CutoverDataset::OpeningReceivables->value => ImportOpeningReceivables::class,
            CutoverDataset::OpeningPayables->value => ImportOpeningPayables::class,
            CutoverDataset::OpeningOdometerReadings->value => ImportOpeningOdometerReadings::class,
            CutoverDataset::OpeningTankStock->value => ImportOpeningTankStock::class,
            CutoverDataset::OpeningDriverAdvances->value => ImportOpeningDriverAdvances::class,
            CutoverDataset::OpeningLoans->value => ImportOpeningLoans::class,
            CutoverDataset::Customers->value => ImportCustomers::class,
            CutoverDataset::Suppliers->value => ImportSuppliers::class,
            CutoverDataset::Trucks->value => ImportTrucks::class,
            CutoverDataset::Trailers->value => ImportTrailers::class,
            CutoverDataset::Employees->value => ImportEmployees::class,
            CutoverDataset::Drivers->value => ImportDrivers::class,
            CutoverDataset::Routes->value => ImportRoutes::class,
            CutoverDataset::Rates->value => ImportRates::class,
            CutoverDataset::Parts->value => ImportParts::class,
        ]));

        // The scan step, chosen by config because Q8 is open: `none` is this machine and any
        // environment without a sidecar, `clamav` is the Linux design. The upload Action asks for
        // `VirusScanner` and never learns which of the two it got — which is the only reason
        // answering Q8 later is a config edit and not a rewrite of the intake path.
        $this->app->bind(VirusScanner::class, function (): VirusScanner {
            /** @var string $driver */
            $driver = (string) config('fleet.documents.scanner', 'none');

            // clamav is the Linux sidecar named in. The host is a config value because the
            // sidecar's address is a deployment fact, not a domain one.
            if ($driver === 'clamav') {
                return new ClamAvScanner(
                    (string) config('fleet.documents.clamav_host', ''),
                    (int) config('fleet.documents.clamav_port', 3310),
                );
            }

            // `none` and anything unrecognised both land here. An unknown scanner name is a typo in
            // an environment variable, and the safe reading of a typo is "nothing judged these
            // bytes" — not "assume the thing you meant to configure did".
            return new NoScanner;
        });

        // The reminder sources, behind a tag rather than a hard-coded array inside the engine.
        // This is the shape that keeps the D2 rule true once Phase 1 ships: the engine resolves
        // `reminder_sources` and receives objects implementing an interface it owns, so adding a Fleet or
        // Maintenance source means one line here — in `app/Providers`, which may name modules — and no
        // import anywhere under `app/Domain/Kernel`.
        $this->app->singleton(DocumentExpirySource::class);
        $this->app->singleton(DriverPaperExpirySource::class);
        $this->app->singleton(ServiceDueSource::class);
        $this->app->singleton(LoanDueSource::class);
        $this->app->tag([DocumentExpirySource::class, DriverPaperExpirySource::class, ServiceDueSource::class, LoanDueSource::class], 'reminder_sources');

        $this->app->bind(ReminderRunner::class, function (): ReminderRunner {
            /** @var array<ReminderSource> $sources */
            $sources = iterator_to_array($this->app->tagged('reminder_sources'));

            return new ReminderRunner(
                $this->app->make(ThresholdRepository::class),
                $this->app->make(RaiseAlert::class),
                $this->app->make(AlertDelivery::class),
                $this->app->make(AuditRecorder::class),
                $sources,
            );
        });
    }

    public function boot(): void
    {
        // A queue worker is one long-lived process. Without this, a job that pinned a company
        // would leave that pin set for whatever runs next on the same worker — a cross-tenant
        // read with no request to blame it on. Failure counts too: the job may have died after
        // pinning.
        Event::listen([JobProcessed::class, JobFailed::class], function (): void {
            CurrentCompany::forget();
        });

        // Signing out ends the device's record. The guard fires this before it touches the session
        // store, so the id still belongs to the session that just ended.
        Event::listen(Logout::class, EndSessionOnLogout::class);
    }
}
