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
 * §6.8's `PaymentReceived`: the money is in the bank, and the receipt says so before anyone says which
 * invoice it was for.
 *
 * The credit is **`2150 Unapplied customer cash`**, not `1150 AR`. That is the design's answer to an
 * overpayment: an invoice is never "adjusted" by money that arrived without one being named, and a payment
 * that is applied to nothing is still real money in the bank (§4.2, §10.4). {@see AllocatePayment} is the
 * second seam — `Dr 2150 / Cr 1150` — and it is where an invoice's balance actually moves.
 *
 * The receipt number is allocated **at creation** (§7.4's rule applies to drafts, and a bank fact has no
 * draft state), inside the same transaction as the posting, so a rollback un-burns it.
 *
 * Base currency only, like {@see IssueInvoice}: a ZAR receipt is refused with a sentence naming the FX
 * slice rather than converted at a rate nobody agreed.
 */
final class RecordPayment
{
    /** The liability a receipt credits until an allocation spends it (§6.8). */
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
            throw new InvalidArgumentException('Recording a receipt needs the payments.allocate ability (§9.3).');
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
                'Account %s is not in this company\'s chart, so a %s receipt cannot be banked (§6.8).',
                $dto->method->accountCode(),
                $dto->method->label(),
            ));
        }

        if ($unapplied === null) {
            throw new InvalidArgumentException('Account 2150 Unapplied customer cash is not in this company\'s chart, so the receipt cannot be posted (§6.8).');
        }

        $receivedOn = Carbon::instance($dto->receivedOn)->startOfDay();
        $dimensions = array_filter([
            'customer_id' => $customer->id,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return DB::transaction(function () use ($dto, $actorId, $customer, $bank, $unapplied, $receivedOn, $baseCurrency, $dimensions): Payment {
            $number = $this->numbers->allocate('receipt', $receivedOn, $actorId);

            $payment = Payment::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => PaymentStatus::Recorded->value,
                'customer_id' => $customer->id,
                'received_on' => $receivedOn->toDateString(),
                'method' => $dto->method->value,
                'reference' => $dto->reference === null || trim($dto->reference) === '' ? null : mb_substr(trim($dto->reference), 0, 80),
                // Base currency only in this slice, so §5.2's block is the identity (§20.33).
                'currency_code' => $baseCurrency,
                'amount' => $dto->amountMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $receivedOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $dto->amountMinor,
                // Nothing has claimed it yet — that is what "on account" means, and the CHECK on this
                // column is what keeps the ledger's 2150 credit and this row in step.
                'unapplied_amount' => $dto->amountMinor,
                'unapplied_base' => $dto->amountMinor,
                'source_uuid' => $dto->sourceUuid === null || $dto->sourceUuid === '' ? null : $dto->sourceUuid,
                'recorded_by' => $actorId,
            ]);

            $this->ledger->post(new PostingDto(
                description: sprintf('Receipt %s · %s', $number, $customer->name),
                postedOn: $receivedOn,
                currencyCode: $baseCurrency,
                fxRate: '1',
                fxRateSource: FxRateSource::Manual,
                fxRateDate: $receivedOn,
                lines: [
                    new LineDto(
                        accountId: $bank->id,
                        side: Side::Debit,
                        base: Money::ofMinor($dto->amountMinor, $baseCurrency),
                        note: $dto->method->label(),
                        dimensions: $dimensions,
                    ),
                    new LineDto(
                        accountId: $unapplied->id,
                        side: Side::Credit,
                        base: Money::ofMinor($dto->amountMinor, $baseCurrency),
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
                ],
                actorId: $actorId,
            );

            return $payment->refresh();
        }, 3);
    }
}
