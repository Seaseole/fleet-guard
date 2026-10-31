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
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
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
 * §5.5's allocation rule, and §6.8's second seam: money leaves `2150` and settles `1150`.
 *
 * The walk is **FIFO by receipt age** over the customer's unapplied money: the oldest money is spent first,
 * so an overpayment from March is not left sitting while April's receipt settles March's invoice. Each
 * allocation is its own row and its own ledger entry, because §6.8 keeps `PaymentReceived` and
 * `PaymentAllocated` as two seams — and because a reversed allocation must stay as evidence rather than
 * editing history (§10.4).
 *
 * The **residue rule** is the last line of that walk: when the money available lands within one minor unit
 * of the balance, the final allocation absorbs the difference so a paid invoice's balance reaches **exactly
 * zero** rather than leaving a cent that no future allocation could ever clear (§5.5). Absorbing is only
 * allowed when that receipt actually has the extra unit left; a receipt cannot give what it does not hold.
 *
 * Only an `issued` invoice can receive money. A void one is not owed anything, and taking a payment against
 * it would post a receivable credit with no receivable.
 */
final class AllocatePayment
{
    private const UNAPPLIED_CASH_ACCOUNT = '2150';

    private const RECEIVABLE_ACCOUNT = '1150';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, ?int $actorId = null, DateTimeInterface|CarbonImmutable|null $on = null): Invoice
    {
        if (! Gate::allows('payments.allocate')) {
            throw new InvalidArgumentException('Allocating a receipt needs the payments.allocate ability (§9.3).');
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

            $payments = Payment::query()
                ->where('customer_id', $locked->customer_id)
                ->where('status', PaymentStatus::Recorded->value)
                ->where('unapplied_amount', '>', 0)
                ->orderBy('received_on')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                throw new InvalidArgumentException(sprintf(
                    'Customer has no unapplied receipts, so nothing can be allocated to %s. Record the payment first (§6.8).',
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

            // §5.5's residue: a single minor unit between the money and the balance is absorbed by the
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

            $accounts = $this->chart->byCode();
            $unapplied = $accounts[self::UNAPPLIED_CASH_ACCOUNT] ?? null;
            $receivable = $accounts[self::RECEIVABLE_ACCOUNT] ?? null;

            if ($unapplied === null || $receivable === null) {
                throw new InvalidArgumentException('Accounts 2150 and 1150 must both be in the chart for an allocation to post (§6.8).');
            }

            $baseCurrency = CurrentCompany::model()->base_currency_code;
            $allocatedOn = $on === null ? Carbon::now()->startOfDay() : Carbon::instance($on)->startOfDay();
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
                // a partly paid one without re-walking the FIFO order (§4.2).
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
}
