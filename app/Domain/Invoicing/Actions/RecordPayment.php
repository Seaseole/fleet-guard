<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Data\PaymentDto;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\PaymentStatus;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The `PaymentReceived`: the money is in the bank, and the receipt says so before anyone says which
 * invoice it was for.
 *
 * The credit is **`2150 Unapplied customer cash`**, not `1150 AR`. That is the design's answer to an
 * overpayment: an invoice is never "adjusted" by money that arrived without one being named, and a payment
 * that is applied to nothing is still real money in the bank. {@see AllocatePayment} is the
 * second seam — `Dr 2150 / Cr 1150` — and it is where an invoice's balance actually moves.
 *
 * The receipt number is allocated **at creation** (the rule applies to drafts, and a bank fact has no
 * draft state), inside the same transaction as the posting, so a rollback un-burns it.
 *
 * A receipt may be foreign since the desk types the currency's amount and either the
 * bank's rate or the base the bank actually took (ADR-0004 #6's two named edits), and the FX
 * difference against the invoice's booked base is realised at allocation, not here —
 * the receipt itself just banks real money.
 */
final class RecordPayment
{
    /** The liability a receipt credits until an allocation spends it. */
    private const UNAPPLIED_CASH_ACCOUNT = '2150';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(PaymentDto $dto, ?int $actorId = null): Payment
    {
        if (! Gate::allows('payments.allocate')) {
            throw new InvalidArgumentException('Recording a receipt needs the payments.allocate ability.');
        }

        if ($dto->amountMinor <= 0) {
            throw new InvalidArgumentException('A receipt is a positive amount. Nothing arrived if it is zero.');
        }

        if ($dto->sourceUuid !== null && $dto->sourceUuid !== '') {
            $existing = Payment::query()->where('source_uuid', $dto->sourceUuid)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $customer = Customer::query()->find($dto->customerId)
            ?? throw new InvalidArgumentException('That customer is not in this company\'s register, so the receipt has no account to sit on.');

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        $accounts = $this->chart->byCode();
        $bank = $accounts[$dto->method->accountCode()] ?? null;
        $unapplied = $accounts[self::UNAPPLIED_CASH_ACCOUNT] ?? null;

        if ($bank === null) {
            throw new InvalidArgumentException(sprintf(
                'Account %s is not in this company\'s chart, so a %s receipt cannot be banked.',
                $dto->method->accountCode(),
                $dto->method->label(),
            ));
        }

        if ($unapplied === null) {
            throw new InvalidArgumentException('Account 2150 Unapplied customer cash is not in this company\'s chart, so the receipt cannot be posted.');
        }

        $receivedOn = Carbon::instance($dto->receivedOn)->startOfDay();
        $dimensions = array_filter([
            'customer_id' => $customer->id,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        // + A foreign receipt freezes its own base amount at the rate the bank struck
        // (or the base the bank took, whose implied rate becomes manual_override — ADR-0004 #6's
        // two named edits). A base receipt is the identity block, exactly as left it.
        $fx = $dto->isForeign()
            ? $this->buildFx($dto, $baseCurrency, $receivedOn)
            : null;

        $cashMinor = $fx?->baseAmount()->minorUnits ?? $dto->amountMinor;

        // What the paper says, as opposed to what the ledger books: the currency and rate the desk
        // worked from, which is the pair a later realised difference is measured against.
        $documentCurrency = $fx === null ? $baseCurrency : $fx->foreign->currencyCode;
        $documentRate = $fx === null ? '1' : $fx->rate;

        return DB::transaction(function () use ($dto, $actorId, $customer, $bank, $unapplied, $receivedOn, $baseCurrency, $dimensions, $fx, $cashMinor, $documentCurrency, $documentRate): Payment {
            $number = $this->numbers->allocate('receipt', $receivedOn, $actorId);

            $payment = Payment::create(array_merge([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => PaymentStatus::Recorded->value,
                'customer_id' => $customer->id,
                'received_on' => $receivedOn->toDateString(),
                'method' => $dto->method->value,
                'reference' => $dto->reference === null || trim($dto->reference) === '' ? null : mb_substr(trim($dto->reference), 0, 80),
                // Nothing has claimed it yet — that is what "on account" means, and the CHECK on these
                // two columns is what keeps the ledger's 2150 credit and this row in step. The pile is
                // kept in the receipt's *own* currency beside its frozen base: `unapplied_amount <=
                // amount` stays true for a ZAR receipt (both in ZAR), and `invoices:recompute-balances`
                // rebuilds each half from the allocation rows that carry it.
                'unapplied_amount' => $dto->amountMinor,
                'unapplied_base' => $cashMinor,
                'source_uuid' => $dto->sourceUuid === null || $dto->sourceUuid === '' ? null : $dto->sourceUuid,
                'recorded_by' => $actorId,
            ], $fx?->toColumns() ?? [
                'currency_code' => $baseCurrency,
                'amount' => $dto->amountMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $receivedOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $dto->amountMinor,
            ]));

            $this->ledger->post(new PostingDto(
                description: $fx === null
                    ? sprintf('Receipt %s · %s', $number, $customer->name)
                    : sprintf('Receipt %s · %s · %s at %s', $number, $customer->name, $documentCurrency, $documentRate),
                postedOn: $receivedOn,
                currencyCode: $baseCurrency,
                fxRate: '1',
                fxRateSource: FxRateSource::Manual,
                fxRateDate: $receivedOn,
                lines: [
                    new LineDto(
                        accountId: $bank->id,
                        side: Side::Debit,
                        base: Money::ofMinor($cashMinor, $baseCurrency),
                        note: $dto->method->label(),
                        dimensions: $dimensions,
                    ),
                    new LineDto(
                        accountId: $unapplied->id,
                        side: Side::Credit,
                        base: Money::ofMinor($cashMinor, $baseCurrency),
                        note: $number,
                        dimensions: $dimensions,
                    ),
                ],
                sourceType: 'payment',
                sourceId: $payment->id,
                createdBy: $actorId,
            ));

            $this->audit->operational(
                aggregateType: 'payment',
                aggregateId: $payment->id,
                fromState: null,
                toState: PaymentStatus::Recorded->value,
                context: [
                    'number' => $number,
                    'customer_id' => $customer->id,
                    'amount' => $dto->amountMinor,
                    'method' => $dto->method->value,
                    // Which currency, and what it froze to — the pair a realised difference is
                    // later measured against.
                    'currency_code' => $documentCurrency,
                    'base_amount' => $cashMinor,
                    'fx_rate' => $documentRate,
                ],
                actorId: $actorId,
            );

            return $payment->refresh();
        }, 3);
    }

    /**
     * ADR-0004 #6's two named edits, both arriving through Fx so the rounding is one place:
     * typing the foreign amount at a rate (`foreignToBase`), or typing the base the bank
     * actually took (`withBaseAmount`, whose implied rate is `manual_override`). Anything else —
     * an unknown source, a missing rate — is refused here rather than guessed at the ledger.
     */
    private function buildFx(PaymentDto $dto, string $baseCurrency, Carbon $receivedOn): Fx
    {
        $source = FxRateSource::tryFrom($dto->fxRateSource);

        if ($source === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not a rate source the books recognise.', $dto->fxRateSource));
        }

        if ($dto->baseAmountMinor !== null) {
            return Fx::withBaseAmount(
                $dto->amountMinor,
                $dto->currency(),
                $dto->baseAmountMinor,
                $baseCurrency,
                $receivedOn,
                $dto->fxRateRef,
            );
        }

        $rate = $dto->rate();

        if ($rate === null) {
            throw new InvalidArgumentException(sprintf(
                'A %s receipt needs either the rate the bank struck or the base it actually took — the ledger books Pula, '
                .'and only the bank paper says how many.',
                $dto->currency(),
            ));
        }

        return Fx::foreignToBase(
            $dto->amountMinor,
            $dto->currency(),
            $rate,
            $baseCurrency,
            $source,
            $receivedOn->toImmutable(),
            $dto->fxRateRef,
        );
    }
}
