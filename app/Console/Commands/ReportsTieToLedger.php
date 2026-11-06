<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The tie-out: operational screens and the statutory ledger must tell the same story, and
 * "with it, 'difference: 3 unposted chargeables' is a screen instead of a suspicion". Three
 * questions per company, each answered by comparing a module's own rows to the ledger read
 * through its doors:
 *
 *  1. every trip that carries costs: do the `trip_costs` rows add up to the ledger's net
 *     expense movement on that trip's dimension — the derivation, checked per trip?
 *  2. do the issued invoices' open balances add up to account 1150? (Receipts no Document has
 *     applied stay in 1150's own credit; the unapplied half rides on 2150, so it does
 *     not touch this equality — that is *why* it can be a CHECK and not a shrug.)
 *  3. do the live bills' balances, less supplier money nobody has applied yet (the
 *     undimensioned debit on 2110), add up to account 2110?
 *
 * A difference on trip 1 or 2 is a bug: one side moved without the other. Every finding names
 * the figure the ledger has and the figure the module has, because the number that is wrong is
 * the one the owner needs to see first.
 */
final class ReportsTieToLedger extends Command
{
    protected $signature = 'reports:tie-to-ledger
                            {--company= : Only this company (default: every company)}
                            {--trip= : Only this trip id (the diagnosis door a finding leads to)}
                            {--quiet-ok : Print only problems}';

    protected $description = 'Reconcile the operational reports against the ledger and alarm on difference';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly SelfCheckAlerter $alerter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : DB::table('companies')->orderBy('id')->pluck('id')->all();

        $findings = [];

        foreach ($companyIds as $companyId) {
            CurrentCompany::force($companyId);

            try {
                foreach ($this->tripFindings((int) $companyId) as $finding) {
                    $findings[] = $finding;
                }

                $findings[] = $this->receivableFinding((int) $companyId);

                foreach ($this->payableFinding((int) $companyId) as $finding) {
                    $findings[] = $finding;
                }
            } finally {
                CurrentCompany::forget();
            }
        }

        $findings = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['finding'] !== '',
        ));

        $quiet = $this->option('quiet-ok') === true;

        if ($findings === []) {
            if (! $quiet) {
                $this->info('The ledger and the operational rows tie, per trip and per control account.');
            }

            $this->alerter->clear('reports:tie-to-ledger', 'the tie-out ran clean', null);

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line(sprintf(' DRIFT  company %d — %s', $finding['company'], $finding['finding']));
        }

        $raised = $this->alerter->reportFindings('reports:tie-to-ledger', 'Reports no longer tie to the ledger', $findings);

        foreach ($raised['unattributable'] as $line) {
            $this->error('unattributable: '.$line);
        }

        return self::FAILURE;
    }

    /**
     * @return list<array{company: int, finding: string}>
     */
    private function tripFindings(int $companyId): array
    {
        // A static closure has no bound $this, so the id is read before the builder is built.
        $tripId = $this->option('trip') === null ? null : (string) $this->option('trip');

        /** @var Collection<int, Trip> $trips */
        $trips = Trip::query()
            ->when($tripId !== null, static fn ($q) => $q->where('id', $tripId))
            ->where(fn ($q) => $q->whereNotNull('ledger_posted_at')->orWhereHas('tripCosts'))
            ->orderBy('trip_no')
            ->get(['id', 'trip_no']);

        if ($trips->isEmpty()) {
            return [];
        }

        $types = [];

        foreach ($this->chart->byCode() as $code => $account) {
            $types[$code] = $account->type->value;
        }

        $findings = [];

        foreach ($trips as $trip) {
            $booked = (int) TripCost::query()->where('trip_id', $trip->id)->sum('base_amount');

            $posted = 0;

            foreach ($this->ledger->totalsByAccount(['trip_id' => $trip->id]) as $turnover) {
                if (($types[$turnover->accountCode] ?? '') === 'expense') {
                    $posted += $turnover->signedDebit();
                }
            }

            if ($booked === 0 && $posted === 0) {
                continue;
            }

            if ($booked !== $posted) {
                $findings[] = [
                    'company' => $companyId,
                    'finding' => "trip {$trip->trip_no}: trip_costs hold {$booked} minor but the ledger moved {$posted} on its expense lines",
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array{company: int, finding: string}
     */
    private function receivableFinding(int $companyId): array
    {
        $owed = (int) Invoice::query()
            ->where('status', InvoiceLifecycle::Issued->value)
            ->sum('balance_base');

        $net = 0;

        foreach ($this->ledger->statementTotals() as $turnover) {
            if ($turnover->accountCode === '1150') {
                $net += $turnover->signedDebit();
            }
        }

        if ($net === $owed) {
            return ['company' => $companyId, 'finding' => ''];
        }

        return [
            'company' => $companyId,
            'finding' => "account 1150: the ledger holds {$net} minor debit but issued invoices add up to {$owed}",
        ];
    }

    /**
     * The payable check runs per bill, because 2110 legitimately carries two populations: the
     * bill-dimensioned postings this command owns, and the *obligations* a delivery or a
     * receipt already put through the ledger before any paper existed — those ride 2110 with no
     * bill dimension and are the linked-document model's to reconcile (5c), not this loop's.
     *
     * @return list<array{company: int, finding: string}>
     */
    private function payableFinding(int $companyId): array
    {
        /** @var Collection<int, Bill> $bills */
        $bills = Bill::query()
            ->where('status', '!=', BillLifecycle::Void->value)
            ->whereNull('linked_source')
            ->get(['id', 'number', 'balance_amount']);

        $findings = [];

        foreach ($bills as $bill) {
            $net = 0;

            foreach ($this->ledger->totalsByAccount(['bill_id' => $bill->id]) as $turnover) {
                if ($turnover->accountCode === '2110') {
                    $net += $turnover->signedDebit();
                }
            }

            if ((int) $bill->balance_amount !== -$net) {
                $findings[] = [
                    'company' => $companyId,
                    'finding' => "bill {$bill->number}: 2110 holds ".(-$net).' minor but the bill\'s balance projection says '.$bill->balance_amount,
                ];
            }
        }

        return $findings;
    }
}
