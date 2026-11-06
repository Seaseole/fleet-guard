<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Support\LedgerVerifier;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The pre-close report (Phase 5's "a real month closes": nothing posts into it afterwards,
 * the trial balance balances, the tie-out is clean, and the reconciliations are signed).
 *
 * Four blocking questions, each answered from the same doors the screens use — the Kernel's
 * period projection, the verifier (layer 5), the nightly tie-out command,
 * the reconciliation runs of and the windowed movement read of and two advisory
 * ones that must not block a close nobody asked for (a failed mail job is no reason to keep
 * October open, but the accountant signing should see it).
 *
 * The report never writes, and it never imports a ledger model: D3 is exactly the rule that
 * keeps this class honest when the tempting shortcut is a `Period::query()`. Closing itself is
 * the Kernel's ClosePeriod Action; this is the page a person reads before asking for it, which
 * is why it returns rows someone can argue with rather than a boolean.
 */
final class ClosePeriodValidation
{
    public function __construct(
        private readonly LedgerVerifier $verifier,
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * @return array{period: array{period: string, starts_on: string, ends_on: string, status: string, closed_at: string|null, closed_by: int|null}|null, checks: list<array{key: string, label: string, ok: bool, detail: string, blocking: bool}>, canClose: bool}
     */
    public function run(string $periodKey): array
    {
        $period = $this->ledger->periodSummary($periodKey);

        if ($period === null) {
            return [
                'period' => null,
                'checks' => [[
                    'key' => 'period_exists',
                    'label' => 'The month has a period row',
                    'ok' => false,
                    'detail' => "No {$periodKey} row exists — a month the ledger never saw has nothing to close.",
                    'blocking' => true,
                ]],
                'canClose' => false,
            ];
        }

        $checks = [];

        $checks[] = [
            'key' => 'period_open',
            'label' => 'The month is open',
            'ok' => $period['status'] === 'open',
            'detail' => $period['status'] === 'open'
                ? 'open — closable.'
                : "status '{$period['status']}' — close only moves open → closed.",
            'blocking' => true,
        ];

        $drift = array_values(array_filter(
            $this->verifier->findings(),
            static fn (array $f): bool => ($f['company'] ?? null) === CurrentCompany::id(),
        ));

        $checks[] = [
            'key' => 'ledger_verified',
            'label' => 'ledger:verify is clean',
            'ok' => $drift === [],
            'detail' => $drift === []
                ? 'no header disagrees with its lines.'
                : count($drift).' entr'.(count($drift) === 1 ? 'y' : 'ies').' in drift — run ledger:verify and fix before signing the month.',
            'blocking' => true,
        ];

        $tieExit = Artisan::call('reports:tie-to-ledger');

        $checks[] = [
            'key' => 'tie_out_clean',
            'label' => 'reports:tie-to-ledger is clean',
            'ok' => $tieExit === 0,
            'detail' => $tieExit === 0
                ? 'operational screens and the ledger agree company-wide.'
                : 'the tie-out reported at least one disagreement — the close must wait for the numbers to agree.',
            'blocking' => true,
        ];

        $recon = $this->cashReconciledCheck($period['starts_on'], $period['ends_on']);

        $checks[] = $recon;

        $failedJobs = DB::table('failed_jobs')->count();

        $checks[] = [
            'key' => 'failed_jobs',
            'label' => 'Failed jobs (advisory)',
            'ok' => $failedJobs === 0,
            'detail' => $failedJobs === 0 ? 'none.' : "{$failedJobs} failed job(s) on the queue screen — not a blocker for the books, but look before you sign.",
            'blocking' => false,
        ];

        $openRuns = Reconciliation::query()
            ->where('status', ReconciliationStatus::Open->value)
            ->where('statement_on', '<=', $period['ends_on'])
            ->count();

        $checks[] = [
            'key' => 'open_reconciliations',
            'label' => 'Unsigned reconciliation runs (advisory)',
            'ok' => $openRuns === 0,
            'detail' => $openRuns === 0 ? 'none dated up to this month.' : "{$openRuns} run(s) opened on or before {$period['ends_on']} are still unsigned.",
            'blocking' => false,
        ];

        return [
            'period' => $period,
            'checks' => $checks,
            'canClose' => array_reduce(
                $checks,
                static fn (bool $carry, array $c): bool => $carry && (! $c['blocking'] || $c['ok']),
                true,
            ),
        ];
    }

    /**
     * Every linked cash account that actually moved money this month must have an approved
     * reconciliation dated inside the month (the "the reconciliations are signed"); an
     * account with no movement is not asked to produce a signature over nothing, and the
     * question "did it move" is answered by the same windowed ledger read the statements use.
     *
     * @return array{key: string, label: string, ok: bool, detail: string, blocking: bool}
     */
    private function cashReconciledCheck(string $startsOn, string $endsOn): array
    {
        $chart = $this->chart->byCode();

        $movedCodes = [];

        foreach ($this->ledger->statementTotals(CarbonImmutable::parse($startsOn), CarbonImmutable::parse($endsOn)) as $turnover) {
            $movedCodes[(string) $turnover->accountCode] = true;
        }

        $missing = [];

        foreach (BankAccount::query()->where('active', true)->whereNotNull('gl_account_id')->get() as $account) {
            $code = null;

            foreach ($chart as $candidate) {
                if ($candidate->id === $account->gl_account_id) {
                    $code = (string) $candidate->code;

                    break;
                }
            }

            if ($code === null || ! isset($movedCodes[$code])) {
                continue;
            }

            $signed = Reconciliation::query()
                ->where('bank_account_id', $account->id)
                ->where('status', ReconciliationStatus::Approved->value)
                ->whereBetween('statement_on', [$startsOn, $endsOn])
                ->exists();

            if (! $signed) {
                $missing[] = $account->name;
            }
        }

        return [
            'key' => 'cash_reconciled',
            'label' => 'Cash moved this month is reconciled',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? 'every linked account that moved money has a signed run inside the month.'
                : 'no approved reconciliation dated inside the month for: '.implode(', ', $missing).'.',
            'blocking' => true,
        ];
    }
}
