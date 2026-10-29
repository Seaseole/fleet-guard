<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Support;

use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\Actions\TransitionAlert;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * §14.6's last line: "Every one is scheduled; every failure raises an alert."
 *
 * Before this class, `ledger:verify` and the two document sweeps answered drift by exiting 1 — which is
 * correct for a scheduler that alarms and wrong for a human, because nobody watches `schedule:run`'s
 * stdout at 01:00. The drift was therefore recorded in a log file that rotates and invisible on the
 * screen whose entire job is to say what needs attention.
 *
 * **The same door as the reminder path.** A self-check failure is raised through
 * {@see RaiseAlert} like any other condition, which means it gets the cooldown, the episode suffix, the
 * operational-event row and the bell for free. Two raise paths would drift the first time someone
 * tightened one of them.
 *
 * **A clean run closes its own finding.** An integrity alert that stays open forever after the drift is
 * fixed is not a signal, it is wallpaper — and the machine has no way for the scheduler to resolve a
 * row it owns, which is why `alerts.resolved_by_kind` exists. Resolve is the only exit a non-user
 * principal gets; acknowledging and muting stay human decisions ({@see TransitionAlert}).
 *
 * Findings arrive keyed by company because `alerts.company_id` is NOT NULL and a row that cannot name
 * its tenant is a row that will be read by the wrong person. Every finding in the two verifiers *is*
 * attributable: a drift names an entry, and an orphan byte names its company in the storage path
 * `company/{id}/…` (§14.4). A finding that cannot be attributed is a bug in the caller, and it throws
 * here rather than being filed under whichever company happened to be pinned last.
 */
final class SelfCheckAlerter
{
    public function __construct(
        private readonly RaiseAlert $raise,
        private readonly AlertDelivery $delivery,
        private readonly TransitionAlert $transition,
    ) {}

    /**
     * The shape the scheduled commands actually have: a flat list of findings, each naming its company.
     *
     * Grouping lives here rather than in each command because three commands doing the same grouping
     * differently is how one of them ends up filing another tenant's drift under the wrong company.
     *
     * A finding with no company is **returned, not thrown and not guessed**. Throwing would cost the rest
     * of the night's alerts — one stray object at the private disk root would suppress twenty trucks'
     * worth of real findings — and guessing a tenant is how one company reads another's integrity
     * report. The command prints what comes back and exits non-zero, so an unattributable finding is loud
     * without being expensive.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return array{raised: int, unattributable: list<string>}
     */
    public function reportFindings(string $check, string $title, array $findings): array
    {
        /** @var array<int, list<string>> $byCompany */
        $byCompany = [];

        /** @var list<string> $unattributable */
        $unattributable = [];

        foreach ($findings as $finding) {
            if (! array_key_exists('company', $finding) || $finding['company'] === null) {
                $unattributable[] = sprintf(
                    '%s — %s',
                    (string) ($finding['entry_no'] ?? $finding['document_id'] ?? $finding['kind'] ?? $finding['scope'] ?? '-'),
                    (string) ($finding['finding'] ?? $finding['detail'] ?? 'finding'),
                );

                continue;
            }

            $byCompany[(int) $finding['company']][] = sprintf(
                '%s — %s',
                (string) ($finding['entry_no'] ?? $finding['document_id'] ?? $finding['kind'] ?? $finding['scope'] ?? '-'),
                (string) ($finding['finding'] ?? $finding['detail'] ?? 'finding'),
            );
        }

        return [
            'raised' => $byCompany === [] ? 0 : $this->record($check, $title, $byCompany),
            'unattributable' => $unattributable,
        ];
    }

    /**
     * @param  array<int|string, list<string>>  $findingsByCompany  company id => the details it owns
     * @return int how many companies had a finding raised or escalated
     */
    public function record(string $check, string $title, array $findingsByCompany, ?CarbonInterface $today = null): int
    {
        $affected = 0;

        // The pin is restored, not erased: a command that called this while already inside a company must
        // still be inside it when this returns.
        $previous = CurrentCompany::pin();

        try {
            foreach ($findingsByCompany as $companyId => $details) {
                // PHP hands integer-like array keys back as ints, so a caller that built the map from a
                // string company id still arrives as an int. Anything that is not a company id at all is
                // refused here rather than coerced: guessing a tenant is how one company reads another's
                // integrity findings.
                $company = is_int($companyId)
                    ? $companyId
                    : (ctype_digit($companyId) ? (int) $companyId : null);

                if ($company === null) {
                    throw new InvalidArgumentException(sprintf(
                        '%s handed the alert engine a finding keyed "%s" rather than a company id. '
                        .'Every alert names the tenant it belongs to; guessing it from the last pinned '
                        .'context is how one company reads another\'s integrity findings.',
                        $check,
                        (string) $companyId,
                    ));
                }

                CurrentCompany::force($company);

                $outcome = $this->raise->handle($this->candidate($check, $title, $details, $today ?? Carbon::now()));

                if ($outcome->shouldDeliver()) {
                    $this->delivery->deliverToRoles(
                        $outcome->alert,
                        self::recipients(),
                        'the '.$check.' self-check',
                    );
                }

                $affected++;
            }
        } finally {
            CurrentCompany::force($previous);
        }

        return $affected;
    }

    /**
     * Close this check's open findings, because the run just came back clean.
     *
     * `$companyId` is not cosmetic. A sweep narrowed to one tenant says nothing about another: resolving
     * company 1's live drift alert because company 2's run was tidy would retire a real problem on
     * evidence that does not describe it. Pass it whenever the command was given `--company`.
     *
     * @return int the number of alerts resolved
     */
    public function clear(string $check, string $note, ?int $companyId = null): int
    {
        $resolved = 0;

        $previous = CurrentCompany::pin();

        try {
            if ($companyId !== null) {
                $companyIds = [$companyId];
            } else {
                /** @var list<int> $companyIds */
                $companyIds = Company::query()->orderBy('id')->pluck('id')->all();
            }

            foreach ($companyIds as $id) {
                CurrentCompany::force($id);

                // Every episode key, not just the base one. A check that failed, was cleared and failed
                // again sits on `base#2` ({@see RaiseAlert}'s suffix), so matching the base key alone
                // would leave the second occurrence live forever — which is exactly the wallpaper this
                // method exists to prevent, and the reason the key list is derived in one place.
                /** @var Collection<int, Alert> $live */
                $live = Alert::query()
                    ->live()
                    ->where('type', AlertType::SelfCheckFailure->value)
                    ->whereIn('cooldown_key', Alert::episodeKeys($this->baseKey($check)))
                    ->get();

                foreach ($live as $alert) {
                    $this->transition->handle(
                        $alert,
                        AlertState::Resolved,
                        $note,
                        null,
                        null,
                        ActorKind::Scheduler,
                    );

                    $resolved++;
                }
            }
        } finally {
            CurrentCompany::force($previous);
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $details
     */
    private function candidate(string $check, string $title, array $details, CarbonInterface $today): ReminderCandidate
    {
        return new ReminderCandidate(
            type: AlertType::SelfCheckFailure,
            severity: AlertSeverity::Critical,
            family: $check,
            daysBefore: null,
            subjectType: null,
            subjectId: null,
            occurredOn: $today,
            // The check's own name, not a date: a digest mismatch that is still there tomorrow is the
            // same finding, and §14.1's cooldown is about not saying it twice.
            bucket: $check,
            title: $title,
            payload: [
                'check' => $check,
                'finding_count' => count($details),
                'findings' => array_slice($details, 0, 20),
                'band_days' => null,
            ],
        );
    }

    private function baseKey(string $check): string
    {
        return implode(':', [AlertType::SelfCheckFailure->value, '-', '-', $check]);
    }

    /**
     * Who hears about a failed self-check.
     *
     * Config rather than a `reminder_thresholds` row, because the row's shape is "N days before a date"
     * and an integrity drift has no such date. A default of `['owner']` is the honest one: these are the
     * findings that mean a number or a file cannot be trusted, and §9.1 makes the Owner the person who
     * cannot delegate that.
     *
     * @return list<string>
     */
    private static function recipients(): array
    {
        /** @var list<string> $roles */
        $roles = (array) config('fleet.reminders.self_check_recipients', ['owner']);

        return $roles;
    }
}
