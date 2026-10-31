<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\Data\BillLineInput;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fuel\Actions\MarkDeliveryBilled;
use App\Domain\Fuel\Queries\UnbilledDeliveries;
use App\Domain\Invoicing\Queries\EffectiveVat;
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
use App\Domain\Maintenance\Actions\MarkStockReceiptBilled;
use App\Domain\Maintenance\Queries\UnbilledReceipts;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * §6.8 / §15.2 phase 5, slice 5a — the supplier's document for work the fleet had done for it.
 *
 * A free-line bill is the case Phase 3 kept pointing at: an external garage repaired a truck, nothing
 * in the fleet's own doors ever expensed that, and the ledger stayed silent. Recording the bill is
 * where the silence ends — `Dr` the category's expense account and `2126` Input VAT,
 * `Cr 2110` Accounts payable (§6.8's `BillRecorded`, restated onto COA v1 the way §20.25 restated
 * the fuel rows: the section's `5210`/`3100`/`2211` are paper codes this chart does not carry).
 *
 * Ordered steps, one transaction:
 *
 * 1. Refuse anything the seat may not do, and any figure the door cannot keep honest — before a
 *    number is spent (§20.32's ordering lesson).
 * 2. Allocate `BILL-{SEQ:5}` **at creation**: a bill is the supplier's fiscal document, not ours, so
 *    our own reference needs never-reused, not gap-free-at-issue (§7).
 * 3. Write the lines with VAT computed per line from the rate effective on the bill date — stored,
 *    never re-derived at report time (§5.5).
 * 4. Post one entry, dimensions on every line (§6.2): the supplier and this bill on all three sides,
 *    plus the truck and trip the expense account demands (§6.1).
 *
 * A bill that *settles* an obligation Phase 3 already posted (a fuel delivery's or a parts receipt's
 * `Cr 2110`) must **not** post its own entry — the expense or stock debit already happened at the
 * movement, and a second one double-counts it. That reconciliation is slice 5b; this door refuses a
 * linked source by name until then, so a bill here is always a fresh obligation.
 */
final class RecordBill
{
    private const PAYABLE_ACCOUNT = '2110';

    private const INPUT_VAT_ACCOUNT = '2126';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly NumberAllocator $numbers,
        private readonly EffectiveVat $vat,
        private readonly AuditRecorder $audit,
        private readonly UnbilledDeliveries $deliveries,
        private readonly UnbilledReceipts $receipts,
        private readonly MarkDeliveryBilled $stampDelivery,
        private readonly MarkStockReceiptBilled $stampReceipt,
    ) {}

    /**
     * The input-VAT account, asked through the chart's read door so the posting names its own id
     * rather than a model D3 forbids this module to hold.
     *
     * @param  array<string, ChartAccount>  $accounts
     */
    private function vatAccount(array $accounts): ChartAccount
    {
        return $accounts[self::INPUT_VAT_ACCOUNT]
            ?? throw new InvalidArgumentException('Account 2126 Input VAT is not in this company\'s chart, so the bill\'s VAT has nowhere to be claimed (§6.8).');
    }

    /**
     * @param  list<BillLineInput>  $lines  ignored (and refused alongside) when the bill links an obligation
     */
    public function execute(
        int $supplierId,
        array $lines,
        DateTimeInterface $billOn,
        DateTimeInterface $dueOn,
        ?string $supplierDocNo = null,
        ?string $note = null,
        ?string $sourceUuid = null,
        ?BillLinkedSource $linkedSource = null,
        ?string $linkedSourceId = null,
        ?int $actorId = null,
    ): Bill {
        if (! Gate::allows('bills.create')) {
            throw new InvalidArgumentException('Recording a supplier bill needs the bills.create ability (§9.3).');
        }

        // §20.36 — the two shapes, and the schema CHECK that says they are a pair: free lines post
        // their own expense; a link documents what Phase 3 already put on 2110 and posts nothing twice.
        if ($linkedSource === null && $lines === []) {
            throw new InvalidArgumentException('A bill with no lines and no linked delivery would post a zero-value journal entry; the supplier\'s document has nothing on it (§10.4\'s shape applies here too).');
        }

        if (($linkedSource === null) !== ($linkedSourceId === null)) {
            throw new InvalidArgumentException('A linked bill names both the kind of obligation and its id — one without the other is a half of a pair (§4.2).');
        }

        if ($linkedSource !== null && $lines !== []) {
            throw new InvalidArgumentException(sprintf(
                'This bill is a %s paper: it documents an obligation the ledger already holds, so it carries no lines of its own — free lines here would expense the same %s a second time (§20.36).',
                $linkedSource->label(),
                mb_strtolower($linkedSource->label()),
            ));
        }

        $obligation = null;

        if ($linkedSource !== null && $linkedSourceId !== null) {
            $obligation = match ($linkedSource) {
                BillLinkedSource::FuelDelivery => $this->deliveries->find($linkedSourceId),
                BillLinkedSource::StockReceipt => $this->receipts->find($linkedSourceId),
            };

            if ($obligation === null) {
                throw new InvalidArgumentException(sprintf(
                    'That %s is not an unbilled posted obligation in this company — it may already have its paper, or it may be a pump slip whose payable arrives when the diesel is issued (§20.26).',
                    mb_strtolower($linkedSource->label()),
                ));
            }
        }

        if ($sourceUuid !== null && $sourceUuid !== '') {
            $existing = Bill::query()->where('source_uuid', $sourceUuid)->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        $supplier = Supplier::query()->find($supplierId)
            ?? throw new InvalidArgumentException('That supplier is not in this company\'s register, so the bill has no party to owe.');

        // §3f/§20.8's date-column convention: one day, no instant, so a Gaborone midnight bill is the
        // same calendar date the paper document carries.
        $billOn = Carbon::instance($billOn)->startOfDay();
        $dueOn = Carbon::instance($dueOn)->startOfDay();

        if ($dueOn->lessThan($billOn)) {
            throw new InvalidArgumentException('A bill cannot be due before it is dated — the schema\'s CHECK would refuse the row anyway (§4.2).');
        }

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $accounts = $this->chart->byCode();

        $payable = $accounts[self::PAYABLE_ACCOUNT] ?? null;

        if ($payable === null) {
            throw new InvalidArgumentException('Account 2110 Accounts payable is not in this company\'s chart, so the bill has no liability to sit on (§6.8).');
        }

        $rate = $this->vat->on($billOn);
        $taxMinor = 0;
        $prepared = [];

        if ($obligation !== null) {
            // §20.36 — the paper must equal the book, and this slice's book knows no VAT: Phase 3
            // posted the obligation's `Cr 2110` at the movement's own value with input VAT modelled
            // nowhere (§20.26/§20.29's honest gaps). A linked bill is that paper, so it carries the
            // movement's figure and nothing else — refusing here is what stops a silent VAT claim
            // against a posting that never charged any.
            $netMinor = $obligation->amountBase;
            $taxMinor = 0;
        } else {
            foreach ($lines as $index => $line) {
                $accountCode = $line->category->accountCode();
                $account = $accounts[$accountCode] ?? null;

                if ($account === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Account %s (%s) is not in this company\'s chart, so line %d of the bill cannot be posted (§6.2).',
                        $accountCode,
                        $line->category->label(),
                        $index + 1,
                    ));
                }

                if ($line->netMinor <= 0) {
                    throw new InvalidArgumentException(sprintf(
                        'Line %d ("%s") has no value. A zero line on a supplier\'s document is a transcription error, not a free item.',
                        $index + 1,
                        $line->description,
                    ));
                }

                if ($line->currencyCode !== null && $line->currencyCode !== $baseCurrency) {
                    throw new InvalidArgumentException(sprintf(
                        'Line %d is stated in %s and this bill can only be recorded in %s. A supplier\'s foreign invoice needs §5.2\'s '
                        .'rate carried from the document — the FX slice that also settles the ZAR part receipts and work-order lines owns it; '
                        .'a rate invented here would be a ledger entry that disagrees with the paper.',
                        $index + 1,
                        $line->currencyCode,
                        $baseCurrency,
                    ));
                }

                // §6.1's mask is a requirement, so the leg must be named before the ledger is asked.
                foreach ($account->requiredDimensions as $dimension) {
                    $missing = match ($dimension) {
                        'truck' => $line->truckId === null,
                        'trip' => $line->tripId === null,
                        default => true,
                    };

                    if ($missing) {
                        throw new InvalidArgumentException(sprintf(
                            'Line %d ("%s") would post to %s, which requires a %s (§6.1). A %s on a fuel bill must name the leg it was burned on '
                            .'or the cost lands in overheads and per-trip margin stops being true.',
                            $index + 1,
                            $line->description,
                            $accountCode.' '.$account->name,
                            $dimension,
                            $line->category->label(),
                        ));
                    }
                }

                $lineTax = $rate === null ? 0 : $rate->taxOn($line->netMinor);

                if ($lineTax > 0) {
                    $this->vatAccount($accounts);
                }

                $taxMinor += $lineTax;

                $prepared[] = [
                    'input' => $line,
                    'account' => $account,
                    'tax' => $lineTax,
                    'position' => $index,
                ];
            }

            $netMinor = array_sum(array_map(static fn (array $row): int => $row['input']->netMinor, $prepared));
        }

        // The header stores **gross** — what the supplier asks for and what the payment will settle —
        // which is the invoice's `amount`+`tax_amount` folded into one column; an invoice's `amount` is
        // net. Stated because the two documents sit side by side on the same screens.
        $grossMinor = $netMinor + $taxMinor;

        $description = $obligation === null
            ? sprintf(
                'Bill · %s · %s · %d line%s',
                $supplier->name,
                $supplierDocNo !== null && trim($supplierDocNo) !== '' ? trim($supplierDocNo) : 'no supplier doc no',
                count($prepared),
                count($prepared) === 1 ? '' : 's',
            )
            // A linked bill posts nothing, so this sentence is the register's evidence line rather
            // than a journal description — it names the movement whose 2110 credit it documents.
            : sprintf('Bill for %s · %s', mb_strtolower($obligation->kind->label()), $obligation->label);

        return DB::transaction(function () use (
            $supplier, $prepared, $billOn, $dueOn, $supplierDocNo, $note, $sourceUuid, $actorId,
            $baseCurrency, $accounts, $payable, $rate, $netMinor, $taxMinor, $grossMinor, $description,
            $obligation,
        ): Bill {
            // 2. The operational reference is spent at creation (§7): the checks above all passed, so
            //    nothing between here and the row can make the number an orphan.
            $number = $this->numbers->allocate('bill', $billOn, $actorId);

            $bill = Bill::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => BillLifecycle::Recorded->value,
                'supplier_id' => $supplier->id,
                'supplier_doc_no' => $supplierDocNo !== null && trim($supplierDocNo) !== '' ? mb_substr(trim($supplierDocNo), 0, 60) : null,
                'bill_on' => $billOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                // §5.2's block is the identity, base-only, until the FX slice (§20.32's decision 5).
                'currency_code' => $baseCurrency,
                'amount' => $grossMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $billOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $grossMinor,
                'tax_amount' => $taxMinor,
                'tax_base' => $taxMinor,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                'source_uuid' => $sourceUuid === null || $sourceUuid === '' ? null : $sourceUuid,
                // §20.36 — the link and the starting balance. A fresh bill owes its whole gross; the
                // payments slice walks this projection down and `settlementState()` derives from it.
                'linked_source' => $obligation?->kind->value,
                'linked_source_id' => $obligation?->id,
                'balance_amount' => $grossMinor,
                'balance_base' => $grossMinor,
                'recorded_by' => $actorId,
                'recorded_at' => now(),
            ]);

            // The obligation's own module stamps its row — Accounting never writes another module's
            // table (§3.1 D5); the stamp rides in this transaction, so a refused paper un-stamps by
            // rolling back rather than by a second door nobody promised to call.
            if ($obligation !== null) {
                match ($obligation->kind) {
                    BillLinkedSource::FuelDelivery => ($this->stampDelivery)($obligation->id, $bill->id, (int) $bill->company_id),
                    BillLinkedSource::StockReceipt => ($this->stampReceipt)($obligation->id, $bill->id, (int) $bill->company_id),
                };
            }

            $shared = array_filter([
                'supplier_id' => $supplier->id,
                'bill_id' => $bill->id,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $ledgerLines = [];

            foreach ($prepared as $row) {
                /** @var BillLineInput $input */
                $input = $row['input'];

                $bill->lines()->create([
                    'id' => (string) Str::ulid(),
                    'company_id' => CurrentCompany::id(),
                    'description' => mb_substr($input->description, 0, 160),
                    'category' => $input->category->value,
                    'expense_account_code' => $row['account']->code,
                    'truck_id' => $input->truckId,
                    'trip_id' => $input->tripId,
                    'work_order_id' => $input->workOrderId,
                    'net_amount' => $input->netMinor,
                    'net_base' => $input->netMinor,
                    'currency_code' => $baseCurrency,
                    'tax_rate_id' => $row['tax'] > 0 && $rate !== null ? $rate->id : null,
                    'tax_amount' => $row['tax'],
                    'tax_base' => $row['tax'],
                    'position' => $row['position'],
                ]);

                $ledgerLines[] = new LineDto(
                    accountId: $row['account']->id,
                    side: Side::Debit,
                    base: Money::ofMinor($input->netMinor, $baseCurrency),
                    note: $input->category->label(),
                    dimensions: array_filter($shared + [
                        'truck_id' => $input->truckId,
                        'trip_id' => $input->tripId,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );
            }

            // §20.36 — a linked bill posts nothing of its own: the obligation's `Cr 2110` already
            // stands from Phase 3's movement, and mirroring a fresh expense here would fund the same
            // diesel or shelf twice. The paper, the stamp and the audit are its whole write.
            if ($obligation === null) {
                if ($taxMinor > 0) {
                    $ledgerLines[] = new LineDto(
                        // Resolved during the line pass above; the getter keeps that invariant one typed answer.
                        accountId: $this->vatAccount($accounts)->id,
                        side: Side::Debit,
                        base: Money::ofMinor($taxMinor, $baseCurrency),
                        note: 'Input VAT on the bill',
                        dimensions: $shared,
                    );
                }

                $ledgerLines[] = new LineDto(
                    accountId: $payable->id,
                    side: Side::Credit,
                    base: Money::ofMinor($grossMinor, $baseCurrency),
                    note: $number,
                    dimensions: $shared,
                );

                $this->ledger->post(new PostingDto(
                    description: $description,
                    postedOn: $billOn,
                    currencyCode: $baseCurrency,
                    fxRate: '1',
                    fxRateSource: FxRateSource::Manual,
                    fxRateDate: $billOn,
                    lines: $ledgerLines,
                    sourceType: 'bill',
                    sourceId: $bill->id,
                    createdBy: $actorId,
                ));
            }

            $this->audit->operational(
                aggregateType: 'bill',
                aggregateId: $bill->id,
                fromState: null,
                toState: BillLifecycle::Recorded->value,
                context: [
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'gross' => $grossMinor,
                    'net' => $netMinor,
                    'tax' => $taxMinor,
                    'lines' => count($prepared),
                ],
                actorId: $actorId,
            );

            return $bill->refresh()->load('lines');
        }, 3);
    }
}
