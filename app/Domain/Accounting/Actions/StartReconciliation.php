<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Numbering\NumberAllocator;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Opens a reconciliation run (Phase 5, slice 5d): "here is the bank's closing figure for
 * this linked register account on this day — walk the book to it."
 *
 * Three doors guard the start, and each refuses for a reason a clerk can act on. The register
 * row must be linked, because an unlinked row has no book to reconcile. The day must be free,
 * because a second live run for the same account-day would put two signatures on one statement
 * (the retry path is: void the first, the live-row rule — the generated column's unique
 * index is the data-level backstop of this refusal, not this query). And the currency must be
 * the base currency, because the FX slice owns what a USD statement's rate is (the
 * decision 5, and the `reconciliations_base_currency_only` CHECK is the wall).
 */
final class StartReconciliation
{
    public function __construct(
        private readonly ReconciliationMath $math,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        int $bankAccountId,
        DateTimeInterface|string $statementOn,
        int $statementAmountMinor,
        ?string $note = null,
        ?int $actorId = null,
    ): Reconciliation {
        if (! Gate::allows('start', Reconciliation::class)) {
            throw new InvalidArgumentException('Opening a reconciliation needs the bank.reconcile ability.');
        }

        $statementOn = Carbon::parse($statementOn)->startOfDay();

        $account = BankAccount::query()->find($bankAccountId)
            ?? throw new InvalidArgumentException('That register row is not in this company, so there is no account to reconcile.');

        if (! $account->active) {
            throw new InvalidArgumentException('A closed register row is reconciled once, at closing — open it again first, or reconcile against its successor.');
        }

        if ($account->gl_account_id === null) {
            throw new InvalidArgumentException('The register row is not linked to a ledger account yet — link it on the Bank & cash screen, then reconcile.');
        }

        $clash = Reconciliation::query()
            ->where('bank_account_id', $account->id)
            ->where('statement_on', $statementOn->toDateString())
            ->where('status', '!=', ReconciliationStatus::Void->value)
            ->exists();

        if ($clash) {
            throw new InvalidArgumentException('This account already has a live run for that statement date — void it to retry, or reconcile the next statement.');
        }

        $book = $this->math->liveBookBase($account, $statementOn);
        $base = $this->math->baseCurrency();

        return DB::transaction(function () use ($account, $statementOn, $statementAmountMinor, $book, $base, $note, $actorId): Reconciliation {
            $number = $this->numbers->allocate('reconciliation', $statementOn, $actorId);

            $reconciliation = new Reconciliation([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => ReconciliationStatus::Open->value,
                'bank_account_id' => $account->id,
                'statement_on' => $statementOn->toDateString(),
                'currency_code' => $base,
                'amount' => $statementAmountMinor,
                'fx_rate' => '1',
                'fx_rate_source' => 'manual',
                'fx_rate_date' => $statementOn->toDateString(),
                'base_currency_code' => $base,
                'base_amount' => $statementAmountMinor,
                'book_base_amount' => $book,
                'note' => $note,
                'started_by' => $actorId,
            ]);

            $reconciliation->save();

            $this->audit->operational(
                'reconciliation',
                $reconciliation->id,
                null,
                ReconciliationStatus::Open->value,
                reason: "opened {$account->name} at {$statementOn->toDateString()}",
                context: ['number' => $number, 'book_base' => $book, 'statement_base' => $statementAmountMinor],
                actorId: $actorId,
            );

            return $reconciliation;
        });
    }
}
