<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Models\PaymentAllocation;
use App\Domain\Invoicing\PaymentStatus;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The allocation rule, and the second seam: money leaves `2150` and settles `1150`.
 *
 * The walk is **FIFO by receipt age** over the customer's unapplied money: the oldest money is spent first,
 * so an overpayment from March is not left sitting while April's receipt settles March's invoice. Each
 * allocation is its own row and its own ledger entry, because the design keeps `PaymentReceived` and
 * `PaymentAllocated` as two seams — and because a reversed allocation must stay as evidence rather than
 * editing history.
 *
 * The **residue rule** is the last line of that walk: when the money available lands within one minor unit
 * of the balance, the final allocation absorbs the difference so a paid invoice's balance reaches **exactly
 * zero** rather than leaving a cent that no future allocation could ever clear. Absorbing is only
 * allowed when that receipt actually has the extra unit left; a receipt cannot give what it does not hold.
 *
 * Only an `issued` invoice can receive money. A void one is not owed anything, and taking a payment against
 * it would post a receivable credit with no receivable.
 */
final class AllocatePayment
{
    private const UNAPPLIED_CASH_ACCOUNT = '2150';

    private const RECEIVABLE_ACCOUNT = '1150';

    private const FX_GAIN_ACCOUNT = '7510';

    private const FX_LOSS_ACCOUNT = '7511';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, ?int $actorId = null, DateTimeInterface|CarbonImmutable|null $on = null): Invoice
    {
        if (! Gate::allows('payments.allocate')) {
            throw new InvalidArgumentException('Allocating a receipt needs the payments.allocate ability.');
        }

        return DB::transaction(function () use ($invoice, $actorId, $on): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceLifecycle::Issued) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is %s, so it is not owed anything and cannot receive a payment.',
                    $locked->reference(),
                    mb_strtolower($locked->status->label()),
                ));
            }

            if ($locked->balance_amount <= 0) {
                throw new InvalidArgumentException(sprintf('Invoice %s has no balance left to allocate against.', $locked->reference()));
            }

            $accounts = $this->chart->byCode();
            $unapplied = $accounts[self::UNAPPLIED_CASH_ACCOUNT] ?? null;
            $receivable = $accounts[self::RECEIVABLE_ACCOUNT] ?? null;

            if ($unapplied === null || $receivable === null) {
                throw new InvalidArgumentException('Accounts 2150 and 1150 must both be in the chart for an allocation to post.');
            }

            $baseCurrency = CurrentCompany::model()->base_currency_code;

            if ($locked->currency_code !== $baseCurrency) {
                return $this->settleForeign($locked, $actorId, $on, $baseCurrency, $accounts, $unapplied, $receivable);
            }

            $payments = Payment::query()
                ->where('customer_id', $locked->customer_id)
                ->where('status', PaymentStatus::Recorded->value)
                ->where('unapplied_amount', '>', 0)
                // A ZAR receipt is not Pula: the walk over a base invoice spends base money
                // only; foreign receipts settle invoices of their own currency.
                ->where('currency_code', $baseCurrency)
                ->orderBy('received_on')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                throw new InvalidArgumentException(sprintf(
                    'Customer has no unapplied receipts, so nothing can be allocated to %s. Record the payment first.',
                    $locked->reference(),
                ));
            }

            $remaining = $locked->balance_amount;

            /** @var list<array{payment: Payment, amount: int}> $planned */
            $planned = [];

            foreach ($payments as $payment) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($remaining, $payment->unapplied_amount);

                if ($take <= 0) {
                    continue;
                }

                $remaining -= $take;
                $planned[] = ['payment' => $payment, 'amount' => $take];
            }

            // The residue: a single minor unit between the money and the balance is absorbed by the
            // last allocation, so a paid invoice reaches exactly zero.
            if ($remaining > 0 && $remaining <= 1 && $planned !== []) {
                $lastIndex = count($planned) - 1;
                $last = $planned[$lastIndex];

                if ($last['payment']->unapplied_amount >= $last['amount'] + $remaining) {
                    $planned[$lastIndex]['amount'] = $last['amount'] + $remaining;
                    $remaining = 0;
                }
            }

            if ($planned === []) {
                throw new InvalidArgumentException(sprintf('Nothing could be allocated to %s.', $locked->reference()));
            }

            $allocatedOn = $on === null ? Carbon::now(CurrentCompany::timezone())->startOfDay() : Carbon::instance($on)->startOfDay();
            $allocatedTotal = 0;
            $lastAllocation = null;

            foreach ($planned as $item) {
                $payment = $item['payment'];
                $amount = $item['amount'];
                $allocatedTotal += $amount;

                $allocation = PaymentAllocation::create([
                    'id' => (string) Str::ulid(),
                    'payment_id' => $payment->id,
                    'invoice_id' => $locked->id,
                    'currency_code' => $baseCurrency,
                    'amount' => $amount,
                    'base_currency_code' => $baseCurrency,
                    'base_amount' => $amount,
                    'has_fx_difference' => false,
                    'fx_difference_base' => 0,
                    'fx_difference_side' => null,
                    'allocated_on' => $allocatedOn->toDateString(),
                    'settled' => false,
                    'reversed' => false,
                    'allocated_by' => $actorId,
                ]);

                $payment->forceFill([
                    'unapplied_amount' => $payment->unapplied_amount - $amount,
                    'unapplied_base' => $payment->unapplied_base - $amount,
                ])->save();

                $this->ledger->post(new PostingDto(
                    description: sprintf(
                        'Receipt %s applied to %s',
                        $payment->number,
                        $locked->reference(),
                    ),
                    postedOn: $allocatedOn,
                    currencyCode: $baseCurrency,
                    fxRate: '1',
                    fxRateSource: FxRateSource::Manual,
                    fxRateDate: $allocatedOn,
                    lines: [
                        new LineDto(
                            accountId: $unapplied->id,
                            side: Side::Debit,
                            base: Money::ofMinor($amount, $baseCurrency),
                            note: $payment->number,
                            dimensions: array_filter([
                                'customer_id' => $locked->customer_id,
                                'invoice_id' => $locked->id,
                            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                        ),
                        new LineDto(
                            accountId: $receivable->id,
                            side: Side::Credit,
                            base: Money::ofMinor($amount, $baseCurrency),
                            note: $locked->reference(),
                            dimensions: array_filter([
                                'customer_id' => $locked->customer_id,
                                'invoice_id' => $locked->id,
                            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                        ),
                    ],
                    sourceType: 'payment_allocation',
                    sourceId: $allocation->id,
                    createdBy: $actorId,
                ));

                $lastAllocation = $allocation;
            }

            $locked->forceFill([
                'balance_amount' => $locked->balance_amount - $allocatedTotal,
                'balance_base' => $locked->balance_base - $allocatedTotal,
            ])->save();

            if ($locked->balance_amount === 0 && $lastAllocation !== null) {
                // The row that closed the invoice is marked, so the tie-out can tell a zeroed balance from
                // a partly paid one without re-walking the FIFO order.
                $lastAllocation->forceFill(['settled' => true])->save();

                $this->audit->operational(
                    aggregateType: 'invoice',
                    aggregateId: $locked->id,
                    fromState: InvoiceLifecycle::Issued->value,
                    toState: 'paid',
                    context: [
                        'balance' => 0,
                        'allocated' => $allocatedTotal,
                        'allocations' => count($planned),
                    ],
                    actorId: $actorId,
                );
            }

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /**
     * The foreign path (the realised-FX rule): a ZAR invoice is settled whole by one
     * ZAR receipt that still holds enough ZAR. One payment, no splits — splitting a foreign
     * settlement across receipts would mix their rates into a blend nobody quoted, and the whole
     * point of the two seams is that the *payment's own* frozen rate decides how much Pula this
     * Pula-worth of ZAR really is.
     *
     * The invoice leaves at its booked base (the open item's rate is the invoice's own);
     * the cash leaves at the payment's rate. The gap is neither a rounding argument nor a fudge —
     * it is the realised gain or loss the corridor earns every week, and it gets its named line
     * on 7510/7511 in the same entry, which is where `ledger:verify` and the statements find it.
     * When the receipt's last foreign units land here the tail is its own remaining base, so the
     * residue rule simply moves one layer up: no half-a-cent of Pula can outlive the ZAR it was
     * taken for, and `unapplied_base` reaches exactly zero with `unapplied_amount`.
     *
     * @param  array<string, ChartAccount>  $accounts
     */
    private function settleForeign(Invoice $locked, ?int $actorId, DateTimeInterface|CarbonImmutable|null $on, string $baseCurrency, array $accounts, ChartAccount $unapplied, ChartAccount $receivable): Invoice
    {
        $gain = $accounts[self::FX_GAIN_ACCOUNT] ?? null;
        $loss = $accounts[self::FX_LOSS_ACCOUNT] ?? null;

        $need = (int) $locked->balance_amount;
        $bookBase = (int) $locked->balance_base;

        $payment = Payment::query()
            ->where('customer_id', $locked->customer_id)
            ->where('status', PaymentStatus::Recorded->value)
            ->where('currency_code', $locked->currency_code)
            ->where('unapplied_amount', '>=', $need)
            ->orderBy('received_on')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            throw new InvalidArgumentException(sprintf(
                'Settling %s needs one %s receipt covering its remaining %s; base-currency money cannot be applied to a %s invoice and partial foreign settlements would blend two rates into neither. Record the receipt or settle it against a smaller balance.',
                $locked->reference(),
                $locked->currency_code,
                $need,
                $locked->currency_code,
            ));
        }

        $isTail = (int) $payment->unapplied_amount === $need;
        $allocatedOn = $on === null ? Carbon::now(CurrentCompany::timezone())->startOfDay() : Carbon::instance($on)->startOfDay();

        // The tail takes whatever base is actually left rather than a recomputed figure, which is
        // what keeps the receipt's own two unapplied columns in step with its frozen rate.
        $cash = $isTail
            ? (int) $payment->unapplied_base
            : min(
                Fx::foreignToBase(
                    $need,
                    $payment->currency_code,
                    (string) $payment->fx_rate,
                    $baseCurrency,
                    FxRateSource::from($payment->fx_rate_source),
                    $allocatedOn,
                )->baseAmount()->minorUnits,
                (int) $payment->unapplied_base,
            );

        $difference = $cash - $bookBase;

        $allocation = PaymentAllocation::create([
            'id' => (string) Str::ulid(),
            'payment_id' => $payment->id,
            'invoice_id' => $locked->id,
            'currency_code' => $locked->currency_code,
            'amount' => $need,
            'base_currency_code' => $baseCurrency,
            'base_amount' => $bookBase,
            'has_fx_difference' => $difference !== 0,
            'fx_difference_base' => abs($difference),
            'fx_difference_side' => $difference === 0 ? null : ($difference > 0 ? 'gain' : 'loss'),
            'allocated_on' => $allocatedOn->toDateString(),
            'settled' => true,
            'reversed' => false,
            'allocated_by' => $actorId,
        ]);

        $remainingForeign = (int) $payment->unapplied_amount - $need;

        $payment->forceFill([
            'unapplied_amount' => $remainingForeign,
            // The tail rule already handed the whole remaining base to this settlement, so the
            // receipt is closed in both columns together — never a ZAR pile with no Pula left.
            'unapplied_base' => $remainingForeign === 0 ? 0 : (int) $payment->unapplied_base - $cash,
        ])->save();

        $lines = [
            new LineDto(
                accountId: $unapplied->id,
                side: Side::Debit,
                base: Money::ofMinor($cash, $baseCurrency),
                note: $payment->number,
                dimensions: ['customer_id' => $locked->customer_id, 'invoice_id' => $locked->id],
            ),
            new LineDto(
                accountId: $receivable->id,
                side: Side::Credit,
                base: Money::ofMinor($bookBase, $baseCurrency),
                note: $locked->reference(),
                dimensions: ['customer_id' => $locked->customer_id, 'invoice_id' => $locked->id],
            ),
        ];

        // The named pair is only asked for when there is something to name: a settlement that lands on
        // the invoice's own booked base needs neither, and a chart without the account is refused at the
        // moment the difference actually appears rather than at every receipt.
        if ($difference > 0) {
            if ($gain === null) {
                throw new InvalidArgumentException('Account 7510 Realised FX gain is not in this company\'s chart, so a gain cannot be booked.');
            }

            $lines[] = new LineDto(
                accountId: $gain->id,
                side: Side::Credit,
                base: Money::ofMinor($difference, $baseCurrency),
                note: 'realised FX: payment rate vs invoice rate',
            );
        } elseif ($difference < 0) {
            if ($loss === null) {
                throw new InvalidArgumentException('Account 7511 Realised FX loss is not in this company\'s chart, so a loss cannot be booked.');
            }

            $lines[] = new LineDto(
                accountId: $loss->id,
                side: Side::Debit,
                base: Money::ofMinor(-$difference, $baseCurrency),
                note: 'realised FX: payment rate vs invoice rate',
            );
        }

        $this->ledger->post(new PostingDto(
            description: sprintf('Receipt %s settles %s (%s)', $payment->number, $locked->reference(), $locked->currency_code),
            postedOn: $allocatedOn,
            currencyCode: $baseCurrency,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $allocatedOn,
            lines: $lines,
            sourceType: 'payment_allocation',
            sourceId: $allocation->id,
            createdBy: $actorId,
        ));

        $locked->forceFill(['balance_amount' => 0, 'balance_base' => 0])->save();

        $this->audit->operational(
            aggregateType: 'invoice',
            aggregateId: $locked->id,
            fromState: InvoiceLifecycle::Issued->value,
            toState: 'paid',
            context: ['balance' => 0, 'cash_base' => $cash, 'book_base' => $bookBase, 'fx_difference' => $difference],
            actorId: $actorId,
        );

        return $locked->refresh()->load('lines');
    }
}
