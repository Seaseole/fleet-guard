<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Alerts;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\LoanInstallment;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The facility's own reminder source (the engine asking Accounting "what needs attention").
 *
 * The ladder is deliberately shorter than the service one — money dated on a schedule needs
 * the accountant, not a workshop slot: fourteen days, seven, then the due day itself, and an
 * overdue instalment is Critical on sight because the lender's own calendar does not have a
 * grace bucket until the paper says so (arrears is a human transition).
 *
 * The candidate subject is the *instalment*, not the loan: one row per due date means the
 * dedupe key the engine keeps (the one-alert-per-condition-per-day) naturally walks the
 * facility instalment by instalment instead of shouting about the same loan every month.
 */
final class LoanDueSource implements ReminderSource
{
    public function key(): string
    {
        return 'loan';
    }

    /**
     * @return list<Threshold>
     */
    public function defaultThresholds(): array
    {
        /** @var list<int> $buckets */
        $buckets = [14, 7, 0];

        $defaults = [];

        foreach ($buckets as $days) {
            $defaults[] = new Threshold('loan', $days, ['accountant']);
        }

        return $defaults;
    }

    /**
     * @return iterable<ReminderCandidate>
     */
    public function candidates(ThresholdSet $thresholds, CarbonInterface $businessToday): iterable
    {
        if (! $thresholds->has('loan')) {
            return [];
        }

        /** @var list<int> $buckets furthest horizon first, from ThresholdSet::buckets() */
        $buckets = $thresholds->buckets('loan');

        if ($buckets === []) {
            return [];
        }

        $today = CarbonImmutable::instance($businessToday)->startOfDay();

        $loanIds = Loan::query()
            ->whereIn('status', [
                LoanLifecycle::Disbursed->value,
                LoanLifecycle::Servicing->value,
                LoanLifecycle::Arrears->value,
                LoanLifecycle::Restructured->value,
            ])
            ->pluck('id')
            ->all();

        if ($loanIds === []) {
            return [];
        }

        $loans = Loan::query()->whereIn('id', $loanIds)->with('supplier')->get()->keyBy('id');

        /** @var LoanInstallment $instalment */
        foreach (LoanInstallment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', [InstallmentStatus::Scheduled->value, InstallmentStatus::Partial->value, InstallmentStatus::Overdue->value])
            ->whereDate('due_on', '<=', $today->copy()->addDays(max($buckets)))
            ->orderBy('due_on')
            ->get() as $instalment
        ) {
            $loan = $loans[$instalment->loan_id] ?? null;

            if ($loan === null) {
                continue;
            }

            $days = (int) $today->diffInDays($instalment->due_on, false);
            $band = $this->bandFor($days, $buckets);

            if ($band === null) {
                continue;
            }

            $overdue = $instalment->due_on->lessThan($today);

            yield new ReminderCandidate(
                type: AlertType::LoanDue,
                severity: $overdue ? AlertSeverity::Critical : AlertSeverity::Warning,
                family: 'loan',
                daysBefore: $band,
                subjectType: 'loan_instalment',
                subjectId: (string) $instalment->id,
                occurredOn: $today,
                bucket: "loan:{$instalment->loan_id}:{$instalment->seq}",
                title: $overdue
                    ? sprintf('%s instalment %d overdue since %s', $loan->reference, $instalment->seq, $instalment->due_on->format('d M Y'))
                    : sprintf('%s instalment %d due %s', $loan->reference, $instalment->seq, $instalment->due_on->format('d M Y')),
                payload: [
                    'loan_id' => $instalment->loan_id,
                    'reference' => $loan->reference,
                    'lender' => $loan->supplier?->name,
                    'instalment_seq' => $instalment->seq,
                    'due_on' => $instalment->due_on->toDateString(),
                    'total_due' => $instalment->total_due,
                    'outstanding' => $instalment->outstandingMinor(),
                    'band_days' => $band,
                ],
            );
        }
    }

    /**
     * The widest configured bucket the condition still fits; null when it is outside all of them.
     *
     * @param  list<int>  $buckets
     */
    private function bandFor(int $days, array $buckets): ?int
    {
        if ($days < 0) {
            return $buckets === [] ? null : max($buckets);
        }

        foreach ($buckets as $band) {
            if ($days <= $band) {
                return $band;
            }
        }

        return null;
    }
}
