<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\BillCategory;
use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\BillLine;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Invoicing\Actions\RecordOpeningReceivable;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
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
 * Phase 0.5's opening payable — a supplier's invoice the fleet had not paid on cutover day.
 *
 * The mirror of {@see RecordOpeningReceivable}, and for the same
 * reason: `2110` cannot be opened as a control total alone, because the payable tie-out walks
 * **every live bill** and compares its balance projection with the ledger's `bill_id`-dimensioned
 * turnover. A supplier's open item that exists only in an aggregate journal line would read as a
 * permanent disagreement between the register and the books — which is precisely the finding this
 * command was written to make impossible.
 *
 * What makes it an opening bill rather than a normal one is a single fact: **the cost is not this
 * system's**. The repair, the diesel, the workshop hours were expensed in the old books in the old
 * year. So the debit side of the entry is `3010` Owner capital, and the one line the bill carries is
 * the `opening` category whose account *is* that equity row — the bill page therefore says, in the
 * column every other bill uses, why the money left the balance sheet instead of the profit-and-loss.
 *
 * No input VAT is claimed. The old system filed it (the VAT split), and a claim here would put a
 * refund on this year's return for a purchase that belongs to last year's.
 *
 * Dates follow the receivable's rule: `bill_on` and `due_on` carry the legacy paper so the AP ageing
 * is honest on day one, while the entry and the fiscal number are today's — an opening balance is a
 * statement about cutover day, and posting it backwards would fight the closed periods.
 */
final class RecordOpeningPayable
{
    private const PAYABLE_ACCOUNT = '2110';

    private const OPENING_EQUITY_ACCOUNT = '3010';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        int $supplierId,
        int $amountMinor,
        DateTimeInterface $billOn,
        DateTimeInterface $dueOn,
        string $legacyReference,
        ?string $description = null,
        ?string $currencyCode = null,
        ?string $fxRate = null,
        ?int $actorId = null,
        ?DateTimeInterface $postedOn = null,
    ): Bill {
        if (! Gate::allows('bills.create')) {
            throw new InvalidArgumentException('Recording an opening payable needs the bills.create ability.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An opening payable is a positive amount owed to a supplier; a zero row is not a document.');
        }

        $reference = trim($legacyReference);

        if ($reference === '' || mb_strlen($reference) > 60) {
            throw new InvalidArgumentException('The supplier\'s own document number is the natural key of an opening row — it must be present and no longer than 60 characters.');
        }

        $supplier = Supplier::query()->find($supplierId)
            ?? throw new InvalidArgumentException('No supplier in this company\'s register carries that id, so the opening payable has no creditor.');

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $currency = $currencyCode === null || $currencyCode === '' ? $baseCurrency : strtoupper($currencyCode);
        $isForeign = $currency !== $baseCurrency;

        // Stops where stopped. A supplier bill is booked in the company's currency:
        // `RecordBill` refuses a foreign line for the same reason, and the payable tie-out compares
        // a bill's `balance_amount` with the ledger line for it — a document-currency figure against a
        // base-currency one, which is a finding waiting for the night to run. The Rand-side clearing
        // bill is Phase 6's to open, along with the tie-out that can read two currencies.
        if ($isForeign) {
            throw new InvalidArgumentException(sprintf(
                'An opening payable must be given in the company currency (%s), not %s: supplier bills are booked in %s '
                .'until the bill-side FX slice lands, the same refusal the door makes.',
                $baseCurrency,
                $currency,
                $baseCurrency,
            ));
        }

        if ($fxRate !== null && $fxRate !== '' && (float) $fxRate !== 1.0) {
            throw new InvalidArgumentException('A base-currency opening payable has no rate to state — 1 unit of the base currency is 1 unit.');
        }

        $raisedOn = Carbon::instance($billOn)->startOfDay();
        $payableBy = Carbon::instance($dueOn)->startOfDay();
        $struckOn = Carbon::instance($postedOn ?? Carbon::now(CurrentCompany::timezone()))->startOfDay();

        if ($payableBy->lt($raisedOn)) {
            throw new InvalidArgumentException(sprintf(
                'The open bill %s from %s falls due %s but was raised %s — a document cannot fall due before it exists.',
                $reference,
                $supplier->name,
                $payableBy->toDateString(),
                $raisedOn->toDateString(),
            ));
        }

        $sourceUuid = 'opening-bill:'.$supplier->code.':'.$reference;

        /** @var Bill|null $already */
        $already = Bill::query()->where('source_uuid', $sourceUuid)->first();

        if ($already !== null) {
            return $already;
        }

        // The identity block reserves for a base-currency document: the rate is 1, and the figure
        // the supplier's paper states is the figure the books carry. `opening_balance` still names
        // where the row came from, because this is an accountant's cutover figure, not a live bill.
        $baseMinor = $amountMinor;

        $accounts = $this->chart->byCode();
        $payable = $this->account($accounts, self::PAYABLE_ACCOUNT);
        $equity = $this->account($accounts, self::OPENING_EQUITY_ACCOUNT);

        return DB::transaction(function () use (
            $supplier, $reference, $description, $raisedOn, $payableBy, $struckOn,
            $amountMinor, $baseMinor, $currency, $baseCurrency, $sourceUuid,
            $payable, $equity, $actorId
        ): Bill {
            $number = $this->numbers->allocate('bill', $struckOn, $actorId);

            $bill = Bill::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => BillLifecycle::Recorded->value,
                'supplier_id' => $supplier->id,
                'supplier_doc_no' => mb_substr($reference, 0, 60),
                'linked_source' => null,
                'linked_source_id' => null,
                'bill_on' => $raisedOn->toDateString(),
                'due_on' => $payableBy->toDateString(),
                'currency_code' => $currency,
                'amount' => $amountMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::OpeningBalance->value,
                'fx_rate_date' => $struckOn->toDateString(),
                'fx_rate_ref' => null,
                'base_currency_code' => $baseCurrency,
                'base_amount' => $baseMinor,
                'tax_amount' => 0,
                'tax_base' => 0,
                'balance_amount' => $amountMinor,
                'balance_base' => $baseMinor,
                'note' => $description === null || $description === ''
                    ? null
                    : mb_substr($description, 0, 500),
                'source_uuid' => $sourceUuid,
                'recorded_by' => $actorId,
                'recorded_at' => Carbon::now(),
            ]);

            // One line, so the bill page answers the question every bill page is asked: what is this
            // money for. Here the answer is the equity row, and `opening` is the category that says so.
            BillLine::create([
                'id' => (string) Str::ulid(),
                'bill_id' => $bill->id,
                'description' => $description === null || $description === ''
                    ? 'Opening balance carried from the legacy system'
                    : mb_substr($description, 0, 160),
                'category' => BillCategory::Opening->value,
                'expense_account_code' => BillCategory::Opening->accountCode(),
                'truck_id' => null,
                'trip_id' => null,
                'work_order_id' => null,
                'net_amount' => $amountMinor,
                'net_base' => $baseMinor,
                'currency_code' => $currency,
                'tax_rate_id' => null,
                'tax_amount' => 0,
                'tax_base' => 0,
                'position' => 1,
            ]);

            $dimensions = array_filter([
                'supplier_id' => $supplier->id,
                'bill_id' => $bill->id,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->ledger->post(PostingDto::inBaseCurrency(
                description: sprintf('Opening payable %s · %s · supplier %s', $number, $supplier->name, $reference),
                postedOn: $struckOn,
                baseCurrencyCode: $baseCurrency,
                lines: [
                    new LineDto(
                        accountId: $equity->id,
                        side: Side::Debit,
                        base: Money::ofMinor($baseMinor, $baseCurrency),
                        note: 'opening balance',
                        dimensions: $dimensions,
                    ),
                    new LineDto(
                        accountId: $payable->id,
                        side: Side::Credit,
                        base: Money::ofMinor($baseMinor, $baseCurrency),
                        note: $reference,
                        dimensions: $dimensions,
                    ),
                ],
                sourceType: 'bill',
                sourceId: $bill->id,
                sourceEventId: 'opening-'.substr(sha1($sourceUuid), 0, 18),
                createdBy: $actorId,
            ));

            $this->audit->operational(
                aggregateType: 'bill',
                aggregateId: $bill->id,
                fromState: null,
                toState: BillLifecycle::Recorded->value,
                reason: "opening payable imported for supplier document {$reference}",
                context: [
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'currency' => $currency,
                    'amount' => $amountMinor,
                    'base_amount' => $baseMinor,
                    'due_on' => $payableBy->toDateString(),
                ],
                actorId: $actorId,
            );

            return $bill->refresh();
        }, 3);
    }

    /**
     * @param  array<string, ChartAccount>  $accounts
     */
    private function account(array $accounts, string $code): ChartAccount
    {
        return $accounts[$code] ?? throw new InvalidArgumentException(sprintf(
            'Account %s is not in this company\'s chart, so an opening balance has nowhere to be posted.',
            $code,
        ));
    }
}
