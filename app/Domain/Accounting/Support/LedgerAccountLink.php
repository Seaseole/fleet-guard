<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\AccountType;
use App\Domain\Kernel\Ledger\LedgerService;
use InvalidArgumentException;

/**
 * The rules for linking a Bank & cash register row to a ledger account (BANK_ACCOUNT's
 * `gl_account_id`), which decides what the cash-flow statement treats as cash: the register
 * names the money routes, the chart names the ledger account behind each one.
 *
 * Both checks read through the Kernel's sanctioned doors (D3) — the chart projection and the
 * statement totals — never the ledger tables. A link may be *set* whenever a posted book
 * already moved money through that chart account (that is the cutover case: the register is
 * opened after the journal), but it may not be *moved or cleared* once the old account has
 * movement: relinking a ledger account with a history would silently rewrite which register
 * row the cash-flow statement reads, and no edit form should be able to do that quietly.
 */
final class LedgerAccountLink
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @return int the accepted id, for chaining
     *
     * @throws InvalidArgumentException when the account cannot carry cash
     */
    public function assertLinkable(int $glAccountId): int
    {
        $account = $this->chart->byCode()[$this->codeOf($glAccountId)] ?? null;

        if ($account === null || $account->id !== $glAccountId) {
            throw new InvalidArgumentException('The ledger account for a cash link must exist in this company\'s chart.');
        }

        if (! $account->isPostable) {
            throw new InvalidArgumentException("Account {$account->code} is a header — it groups accounts, it does not hold money.");
        }

        if ($account->type !== AccountType::Asset) {
            throw new InvalidArgumentException("Account {$account->code} is a {$account->type->label()} account; cash registers link to assets, because cash the business holds is an asset.");
        }

        return $glAccountId;
    }

    /**
     * Guard for edits: changing or clearing the link is refused once the old account has any
     * posted movement. Returns the value to store.
     *
     * @throws InvalidArgumentException when the old link has history and the new one differs
     */
    public function assertChangeSafe(BankAccount $account, ?int $newGlAccountId): ?int
    {
        $old = $account->gl_account_id;

        if ($old === null || $old === $newGlAccountId) {
            return $newGlAccountId === null ? null : $this->assertLinkable($newGlAccountId);
        }

        $oldCode = $this->codeOf($old);
        $hasMovement = false;

        foreach ($this->ledger->statementTotals() as $turnover) {
            if ($turnover->accountCode === $oldCode && $turnover->amountBase !== 0) {
                $hasMovement = true;

                break;
            }
        }

        if ($hasMovement) {
            throw new InvalidArgumentException(
                "Ledger account {$oldCode} already has posted movement — the cash-flow statement would change history under a reader's feet. Void this account and open a new one instead.",
            );
        }

        return $newGlAccountId === null ? null : $this->assertLinkable($newGlAccountId);
    }

    private function codeOf(int $glAccountId): string
    {
        foreach ($this->chart->byCode() as $code => $account) {
            if ($account->id === $glAccountId) {
                return (string) $code;
            }
        }

        throw new InvalidArgumentException('The ledger account for a cash link must exist in this company\'s chart.');
    }
}
