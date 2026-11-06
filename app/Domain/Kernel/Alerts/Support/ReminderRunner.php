<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Support;

use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\ReminderRunReport;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler's whole job: ask every source what is true tonight, record it, tell the people
 * the thresholds name.
 *
 * Nothing here reads a module. The sources arrive as {@see ReminderSource} implementations from the
 * container, which is what keeps the D2 rule — the Kernel may not import a module — true once Fleet,
 * Maintenance and Invoicing exist and each of them wants to report something expiring. The engine's list
 * of concerns is exactly as long as the interfaces it owns.
 *
 * **Company context is pinned per company and dropped in a `finally`.** This is the queue-worker
 * leak's own shape: a worker is one long-lived process, so a sweep that pinned company 1 and left it set
 * would run company 2's documents against company 1's thresholds and role holders, and every read would
 * look normal. `CurrentCompany::force()` is also what makes `CompanyTeamResolver` answer with a real
 * team — without it, spatie's role scope matches no pivot rows and *every* recipient list comes back
 * empty. The alerts would still be created. That is the failure this class is written to make impossible
 * rather than merely unlikely.
 *
 * Sources are read through their normal scoped queries on purpose. A `withoutGlobalScopes()` here would
 * report one company's papers into another's alerts, and unlike the integrity sweep in slice 7 — which is
 * *allowed* to be scope-free because it certifies the whole disk — the reminder path has a tenant column
 * on every row it writes and no reason to guess which company to put in it.
 */
final class ReminderRunner
{
    /**
     * @param  array<ReminderSource>  $sources
     */
    public function __construct(
        private readonly ThresholdRepository $thresholds,
        private readonly RaiseAlert $raise,
        private readonly AlertDelivery $delivery,
        private readonly AuditRecorder $audit,
        private readonly array $sources,
    ) {}

    public function run(): ReminderRunReport
    {
        $report = new ReminderRunReport;

        // Put back the pin that was here, rather than clearing it. The scheduler arrives with no pin and
        // leaves with none; a verifier that called this through `Artisan::call` after pinning company 1
        // must still be inside company 1 when the call returns.
        $previous = CurrentCompany::pin();

        try {
            /** @var list<int> $companyIds */
            $companyIds = Company::query()->orderBy('id')->pluck('id')->all();

            foreach ($companyIds as $companyId) {
                CurrentCompany::force($companyId);

                $this->runForCurrentCompany($report);
            }
        } finally {
            CurrentCompany::force($previous);
        }

        return $report;
    }

    /**
     * One company only — the shape `reminders:run --company=1` needs for a first run or a repair.
     *
     * Separate from `run()` rather than a filter inside it because the two answer different questions:
     * this one is an operator doing something deliberately, and the sweep is the scheduler certifying
     * *every* tenant. A `--company` flag that narrowed the global sweep would let a partial run be
     * reported as the nightly one.
     */
    public function runOne(int $companyId): ReminderRunReport
    {
        $report = new ReminderRunReport;

        $previous = CurrentCompany::pin();

        try {
            CurrentCompany::force($companyId);

            $this->runForCurrentCompany($report);
        } finally {
            CurrentCompany::force($previous);
        }

        return $report;
    }

    private function runForCurrentCompany(ReminderRunReport $report): void
    {
        $report->companies++;

        $this->reopenExpiredMutes($report);

        $thresholds = $this->thresholds->forCurrentCompany();

        if ($thresholds->count() === 0) {
            // Not an error: the Owner may have deleted every row. It is said out loud, because the same
            // emptiness would also be produced by a migration that never seeded, and the two deserve
            // different answers from whoever is reading the run log.
            $report->companiesWithoutThresholds++;

            return;
        }

        // The company's own business day, never the storage zone. A licence expiring at midnight in
        // Gaborone is not expired at 22:00 UTC the night before, and "your truck is not roadworthy today"
        // is not a rounding error.
        $today = Carbon::now(CurrentCompany::timezone());

        foreach ($this->sources as $source) {
            foreach ($source->candidates($thresholds, $today) as $candidate) {
                $report->candidates++;

                $outcome = $this->raise->handle($candidate);

                if ($outcome->isNew) {
                    $report->raised++;
                } else {
                    $report->refreshed++;
                }

                if ($outcome->escalated) {
                    $report->escalated++;
                }

                if (! $outcome->shouldDeliver()) {
                    continue;
                }

                $threshold = $thresholds->find($candidate->family, (int) $candidate->daysBefore);

                if ($threshold === null) {
                    continue;
                }

                $told = $this->delivery->deliver($outcome->alert, $threshold);

                $report->deliveredTo += $told;

                if ($told === 0) {
                    $report->addUnalerted(sprintf(
                        'alert #%d (%s, %s) raised for the %s family at %d days and told nobody — the role '
                        .'keys on that threshold row have no holder in company #%d',
                        $outcome->alert->id,
                        $candidate->type->value,
                        $candidate->title,
                        $candidate->family,
                        (int) $candidate->daysBefore,
                        // Named rather than assumed from the log's ordering: a nightly sweep walks every
                        // tenant, and "the operations_manager role has no holder" is only actionable once
                        // the operator knows *whose* operations_manager is missing.
                        CurrentCompany::id(),
                    ));
                }
            }
        }
    }

    /**
     * "Muted with an expiry so muting cannot silently become permanent".
     *
     * The expiry is only a promise if something acts on it, and that something is this sweep. The mute
     * columns are deliberately *kept*: the row's history says it was muted until a date, and wiping them
     * to make the state read cleanly would destroy the one fact that shows the mute was temporary and
     * timed out on its own.
     */
    private function reopenExpiredMutes(ReminderRunReport $report): void
    {
        /** @var list<Alert> $expired */
        $expired = Alert::query()->muteExpired()->get()->all();

        $report->mutesReopened += count($expired);

        if ($expired === []) {
            return;
        }

        DB::transaction(function () use ($expired): void {
            foreach ($expired as $alert) {
                $alert->forceFill(['state' => AlertState::Open])->save();

                $this->audit->operational(
                    'alert',
                    (string) $alert->id,
                    AlertState::Muted->value,
                    AlertState::Open->value,
                    'The mute reached its expiry on '.($alert->muted_until?->toDateTimeString() ?? 'an unknown date').'.',
                    ['type' => $alert->type->value, 'cooldown_key' => $alert->cooldown_key],
                    ActorKind::Scheduler,
                );
            }
        });
    }
}
