<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\SupplierPayment;
use App\Domain\Accounting\Models\SupplierPaymentAllocation;
use App\Domain\Accounting\SupplierPaymentMethod;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * / Phase 5, slice 5b — money leaving the till toward a supplier: `Dr 2110 / Cr bank-or-cash`.
 *
 * The receipt side's ordering rule is copied here because it is the same accounting truth (the
 * decision 1): **a payment is a bank fact, and the bills it settles are what give it meaning.** One
 * transaction writes the payment, walks its money FIFO over the supplier's open bills by `bill_on`
 * (oldest paper first — what a supplier chases), records one allocation row per bill, and posts one
 * entry whose debit lines carry the `bill_id` dimension. So AP ageing and a supplier's statement are
 * ledger queries, and the "split read from stored rows, never recomputed at report time" holds.
 *
 * Money that no bill has claimed stays as a debit line on `2110` **without** a bill dimension: an
 * advance is the fleet's own claim on its supplier, and the table gives no unapplied-payable account.
 * The `unapplied_amount` projection carries it so a later payment application can find it, and the
 * residue rule from the design applies: when the last application lands within one minor unit of a balance,
 * it absorbs the difference, so a paid bill reaches exactly zero rather than leaving a thebe nobody
 * can ever clear.
 *
 * Base currency only (the decision 5): the FX slice owns the rate a ZAR payment is made at and the
 * realised `7510`/`7511` pair, and inventing one here would be a ledger entry that disagrees with the
 * bank statement.
 *
 * The ability is `bills.create`. The "Expenses & supplier bills" row gives the Accountant and the
 * Owner full rights over the whole pair — entering the paper and paying it — and the catalogue carries
 * no `bills.pay`; the door follows the row rather than inventing a permission (the deviation note).
 */
final class RecordSupplierPayment
{
    private const PAYABLE_ACCOUNT = '2110';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $billIds  bills to settle first; the rest of the supplier's open bills join in FIFO order
     */
    public function execute(
        int $supplierId,
        int $amountMinor,
        SupplierPaymentMethod $method,
        DateTimeInterface $paidOn,
        array $billIds = [],
        ?string $reference = null,
        ?string $note = null,
        ?string $sourceUuid = null,
        ?int $actorId = null,
    ): SupplierPayment {
        if (! Gate::allows('bills.create')) {
            throw new InvalidArgumentException('Paying a supplier bill needs the bills.create ability.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A payment is a positive amount. Nothing left the till if it is zero.');
        }

        if ($sourceUuid !== null && $sourceUuid !== '') {
            $existing = SupplierPayment::query()->where('source_uuid', $sourceUuid)->first();

            if ($existing !== null) {
                return $existing->load('allocations');
            }
        }

        $supplier = Supplier::query()->find($supplierId)
            ?? throw new InvalidArgumentException('That supplier is not in this company\'s register, so the payment has no party to make it to.');

        $paidOn = Carbon::instance($paidOn)->startOfDay();

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $accounts = $this->chart->byCode();

        $payable = $accounts[self::PAYABLE_ACCOUNT] ?? null;
        $cash = $accounts[$method->accountCode()] ?? null;

        if ($payable === null) {
            throw new InvalidArgumentException('Account 2110 Accounts payable is not in this company\'s chart, so the payment has no liability to reduce.');
        }

        if ($cash === null) {
            throw new InvalidArgumentException(sprintf(
                'Account %s is not in this company\'s chart, so a %s payment cannot be made.',
                $method->accountCode(),
                mb_strtolower($method->label()),
            ));
        }

        // The walk happens inside the transaction below, because `SELECT … FOR UPDATE` outside one is
        // a no-op: the lock is released the moment the statement autocommits. Named bills first, then
        // whatever else the supplier is owed, oldest paper first — the order the money is spent in.
        return DB::transaction(function () use (
            $supplier, $amountMinor, $method, $paidOn, $billIds, $reference, $note, $sourceUuid, $actorId,
            $baseCurrency, $payable, $cash,
        ): SupplierPayment {
            $bills = $this->openBills($supplier, $billIds);
            $number = $this->numbers->allocate('supplier_payment', $paidOn, $actorId);

            $remaining = $amountMinor;

            /** @var list<array{bill: Bill, amount: int}> $planned */
            $planned = [];

            foreach ($bills as $bill) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($remaining, $bill->balance_amount);

                if ($take <= 0) {
                    continue;
                }

                $remaining -= $take;
                $planned[] = ['bill' => $bill, 'amount' => $take];
            }

            // There is no residue rule on this side, and that is a difference worth stating (the
            // absorption lives on the receipt): here the money is the ceiling, so a bill left one minor
            // unit short keeps that unit — over-applying would post a debit the payment has no cash
            // behind and break the balance. Money that does not fit a bill is an advance instead, and
            // an advance is a debit on 2110 with no bill dimension, which is honest to the bank.

            $applied = $amountMinor - $remaining;

            $payment = SupplierPayment::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => 'recorded',
                'supplier_id' => $supplier->id,
                'paid_on' => $paidOn->toDateString(),
                'method' => $method->value,
                'reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 80),
                // The block is the identity, base-only, until the FX slice (the decision 5).
                'currency_code' => $baseCurrency,
                'amount' => $amountMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $paidOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $amountMinor,
                'unapplied_amount' => $remaining,
                'unapplied_base' => $remaining,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                'source_uuid' => $sourceUuid === null || $sourceUuid === '' ? null : $sourceUuid,
                'recorded_by' => $actorId,
                'recorded_at' => now(),
            ]);

            $lines = [];
            $closed = [];

            foreach ($planned as $item) {
                /** @var Bill $bill */
                $bill = $item['bill'];

                $closing = $bill->balance_amount - $item['amount'] === 0;

                SupplierPaymentAllocation::create([
                    'id' => (string) Str::ulid(),
                    'company_id' => $payment->company_id,
                    'supplier_payment_id' => $payment->id,
                    'bill_id' => $bill->id,
                    'currency_code' => $baseCurrency,
                    'amount' => $item['amount'],
                    'base_currency_code' => $baseCurrency,
                    'base_amount' => $item['amount'],
                    'applied_on' => $paidOn->toDateString(),
                    // The application that closes the bill is marked as it is written, so the tie-out can
                    // tell a zeroed balance from a partly paid one without re-walking the FIFO order.
                    'settled' => $closing,
                    'reversed' => false,
                    'applied_by' => $actorId,
                ]);

                $bill->forceFill([
                    'balance_amount' => $bill->balance_amount - $item['amount'],
                    'balance_base' => $bill->balance_base - $item['amount'],
                ])->save();

                if ($closing) {
                    $closed[] = ['bill' => $bill, 'amount' => $item['amount']];
                }

                $lines[] = new LineDto(
                    accountId: $payable->id,
                    side: Side::Debit,
                    base: Money::ofMinor($item['amount'], $baseCurrency),
                    note: $bill->number,
                    dimensions: array_filter([
                        'supplier_id' => $supplier->id,
                        'bill_id' => $bill->id,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );
            }

            if ($remaining > 0) {
                // The advance: a debit on 2110 with no bill dimension, because no document has claimed
                // it yet. Leaving it undimensioned is what keeps "who owes whom" answerable per bill.
                $lines[] = new LineDto(
                    accountId: $payable->id,
                    side: Side::Debit,
                    base: Money::ofMinor($remaining, $baseCurrency),
                    note: 'unapplied — no bill has claimed it yet',
                    dimensions: ['supplier_id' => $supplier->id],
                );
            }

            $lines[] = new LineDto(
                accountId: $cash->id,
                side: Side::Credit,
                base: Money::ofMinor($amountMinor, $baseCurrency),
                note: $number,
                dimensions: ['supplier_id' => $supplier->id],
            );

            $this->ledger->post(new PostingDto(
                description: sprintf('Payment %s · %s · %s', $number, $supplier->name, $method->label()),
                postedOn: $paidOn,
                currencyCode: $baseCurrency,
                fxRate: '1',
                fxRateSource: FxRateSource::Manual,
                fxRateDate: $paidOn,
                lines: $lines,
                sourceType: 'supplier_payment',
                sourceId: $payment->id,
                createdBy: $actorId,
            ));

            foreach ($closed as $item) {
                // The lifecycle axis never moves (the three axes, copied to the payable side): what
                // changed is the derived settlement, and the operational trail says so for the reader
                // who asks "when did we stop owing BILL-00007".
                $this->audit->operational(
                    aggregateType: 'bill',
                    aggregateId: $item['bill']->id,
                    fromState: BillLifecycle::Recorded->value,
                    toState: 'paid',
                    context: ['payment' => $number, 'balance' => 0, 'applied' => $item['amount']],
                    actorId: $actorId,
                );
            }

            $this->audit->operational(
                aggregateType: 'supplier_payment',
                aggregateId: $payment->id,
                fromState: null,
                toState: 'recorded',
                context: [
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'amount' => $amountMinor,
                    'applied' => $applied,
                    'unapplied' => $remaining,
                    'method' => $method->value,
                    'bills' => count($planned),
                ],
                actorId: $actorId,
            );

            return $payment->refresh()->load('allocations');
        }, 3);
    }

    /**
     * The supplier's live bills, in the order the money is spent: named ones first, then the rest by
     * `bill_on` oldest-first. Rows are locked here, so a concurrent payment sees the same balances.
     *
     * @param  list<string>  $billIds
     * @return list<Bill>
     */
    private function openBills(Supplier $supplier, array $billIds): array
    {
        $billIds = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $billIds)));

        $open = Bill::query()
            ->where('company_id', $supplier->company_id)
            ->where('supplier_id', $supplier->id)
            ->where('status', BillLifecycle::Recorded->value)
            ->where('balance_amount', '>', 0)
            ->orderBy('bill_on')
            ->orderBy('number')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var list<Bill> $ordered */
        $ordered = [];

        foreach ($billIds as $id) {
            $bill = $open->get($id);

            if ($bill === null) {
                throw new InvalidArgumentException(sprintf(
                    'Bill %s is not an open bill of %s — a void document, a settled one, or another supplier\'s paper cannot receive this money.',
                    $id,
                    $supplier->name,
                ));
            }

            $ordered[] = $bill;
        }

        foreach ($open as $bill) {
            if (! in_array($bill->id, $billIds, true)) {
                $ordered[] = $bill;
            }
        }

        if ($ordered === []) {
            throw new InvalidArgumentException(sprintf(
                '%s has no open bill to settle. Record the supplier\'s paper first — cash that never met a document is an advance, and an advance needs the bill it is waiting for.',
                $supplier->name,
            ));
        }

        return $ordered;
    }
}
