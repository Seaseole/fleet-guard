<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The reconciliation's arithmetic: the book figure the ledger says a linked
 * register account holds at a statement date, and the walk from it to the bank's figure.
 *
 * `liveBookBase` reads the sanctioned unnarrowed door (statementTotals) and keeps only the
 * account's own code — the Accounting module does not touch the line tables (D3). `expected`
 * applies the run's stored items to the book with the stored sign (`statement = book + Σadd −
 * Σsub`), and `variance` is the honest answer to "does the bank agree with us": zero, or the
 * minor units by which it does not. A non-zero variance is not rounded away and not stored
 * as a fact — the approval refuses it, and the next run re-derives it.
 */
final class ReconciliationMath
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    /** The ledger's own balance for the register account at the date, base minor units, signed. */
    public function liveBookBase(BankAccount $account, CarbonInterface $statementOn): int
    {
        if ($account->gl_account_id === null) {
            throw new InvalidArgumentException('A reconciliation needs a register row linked to a ledger account — link it on the Bank & cash screen first.');
        }

        $code = null;

        foreach ($this->chart->byCode() as $candidate) {
            if ($candidate->id === $account->gl_account_id) {
                $code = (string) $candidate->code;

                break;
            }
        }

        if ($code === null) {
            throw new InvalidArgumentException('The linked ledger account is not in this company\'s chart, so there is no book to reconcile against.');
        }

        $balance = 0;

        foreach ($this->ledger->statementTotals(null, $statementOn) as $turnover) {
            if ($turnover->accountCode === $code) {
                $balance += $turnover->side === Side::Debit ? $turnover->amountBase : -$turnover->amountBase;
            }
        }

        return $balance;
    }

    /** Book + Σadd − Σsub: what the bank statement *should* close at, given the book and the items. */
    public function expectedStatementBase(Reconciliation $reconciliation, ?int $bookBase = null): int
    {
        $book = $bookBase ?? $this->bookFor($reconciliation);
        $expected = $book;

        foreach ($reconciliation->relationLoaded('items') ? $reconciliation->items : $reconciliation->items()->get() as $item) {
            $expected += $item->direction->sign() * $item->base_amount;
        }

        return $expected;
    }

    /** The bank's signed figure minus the expected figure. Zero is agreement; anything else is the case. */
    public function varianceBase(Reconciliation $reconciliation, ?int $bookBase = null): int
    {
        return $reconciliation->base_amount - $this->expectedStatementBase($reconciliation, $bookBase);
    }

    /**
     * Which book figure the run answers with: an approved or voided run keeps the snapshot its
     * signer saw; an open run reads the ledger live, because "open" means the walk is still
     * happening under the reader's eyes.
     */
    public function bookFor(Reconciliation $reconciliation): int
    {
        return $reconciliation->status === ReconciliationStatus::Open
            ? $this->liveBookBase($reconciliation->bankAccount, $reconciliation->statement_on)
            : $reconciliation->book_base_amount;
    }

    /** The base currency the run's figures speak in (the register's own currency). */
    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }
}
