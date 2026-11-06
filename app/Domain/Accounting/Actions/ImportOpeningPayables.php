<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Cutover\Support\OpeningCoverage;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 0.5 — the opening payables file.
 *
 * The supplier's side of the same intake as {@see ImportOpeningReceivables}: one row is one unpaid
 * supplier document, and it becomes a recorded bill whose debit is owner capital rather than an
 * expense, because the cost belongs to the year the old system reported.
 *
 * Validation preloads the supplier register for the same reason the master files do — a code that
 * has not arrived yet is a row error the accountant can act on, not an exception that rolls up a
 * thousand rows into one database message.
 */
final class ImportOpeningPayables implements CutoverDatasetHandler
{
    public function __construct(
        private readonly RecordOpeningPayable $record,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $byCode = [];

        foreach (Supplier::query()->get(['id', 'code']) as $supplier) {
            if ($supplier->code !== null && $supplier->code !== '') {
                $byCode[ImportValues::codeKey($supplier->code)] = $supplier->id;
            }
        }

        $seen = [];

        $validation = CutoverRows::read(
            CutoverDataset::OpeningPayables,
            $contents,
            function (CutoverCells $cells) use ($byCode, $companyCurrency, &$seen): CutoverRowResult {
                $errors = [];

                [$code, $error] = ImportValues::code($cells->get('supplier_code'), 24, 'Supplier code', true);
                ImportValues::push($errors, $error);

                [$reference, $error] = ImportValues::text($cells->get('reference'), 60, 'Supplier document number', true);
                ImportValues::push($errors, $error);

                [$description, $error] = ImportValues::text($cells->get('description'), 500, 'Description');
                ImportValues::push($errors, $error);

                $rawCurrency = strtoupper(trim($cells->get('currency_code')));
                $currency = $rawCurrency === '' ? $companyCurrency : $rawCurrency;

                if ($rawCurrency !== '' && preg_match('/^[A-Z]{3}$/', $rawCurrency) !== 1) {
                    $errors[] = 'Currency code must be the three-letter ISO code.';
                }

                [$amountMinor, $error] = ImportValues::money($cells->get('amount'), $currency, 'Amount');
                ImportValues::push($errors, $error);

                if ($amountMinor === null && $errors === []) {
                    $errors[] = 'Amount is required — an open bill with no figure is a reminder, not a payable.';
                }

                if ($amountMinor !== null && $amountMinor <= 0) {
                    $errors[] = 'Amount must be greater than zero; owing a supplier nothing is not a bill.';
                }

                [$dueOn, $error] = ImportValues::date($cells->get('due_on'), 'Due date', true);
                ImportValues::push($errors, $error);

                [$billOn, $error] = ImportValues::date($cells->get('bill_on'), 'Bill date');
                ImportValues::push($errors, $error);

                $rate = trim($cells->get('fx_rate'));

                // The door refuses a supplier bill in a foreign currency, and the payable
                // tie-out reads a bill's document figure against the ledger's base one — so the
                // opening file keeps the same line until Phase 6 gives bills their currency. Naming
                // the rule here saves a row that would be rejected again at apply time.
                if ($currency !== $companyCurrency) {
                    $errors[] = sprintf(
                        'Supplier bills are booked in %s until the bill-side FX slice, so an open %s item must be entered '
                        .'at its %s value on cutover day — the same refusal the door makes.',
                        $companyCurrency,
                        $currency,
                        $companyCurrency,
                    );
                }

                if ($rate !== '' && (preg_match('/^\d{1,9}(\.\d{1,8})?$/', $rate) !== 1 || (float) $rate <= 0)) {
                    $errors[] = 'fx_rate must be a positive number with at most 8 decimals — 1 foreign unit = X base.';
                }

                if ($rate !== '' && $currency === $companyCurrency && (float) $rate !== 1.0) {
                    $errors[] = sprintf('A %s row cannot state a rate other than 1.', $companyCurrency);
                }

                if ($code !== null && ! isset($byCode[ImportValues::codeKey($code)])) {
                    $errors[] = sprintf(
                        'Supplier code "%s" is not in the register. Import the suppliers file first — an opening payable '
                        .'has to name a creditor the AP ageing can address.',
                        $code,
                    );
                }

                if ($dueOn !== null) {
                    // An unbackdated bill is dated the day it falls due, or today when that is still
                    // ahead: `bills_dated_sensibly` refuses a bill that predates its own paper.
                    $billOn ??= min($dueOn, Carbon::now()->toDateString());

                    if (Carbon::parse($billOn)->gt(Carbon::parse($dueOn))) {
                        $errors[] = 'Bill date must be on or before the due date.';
                    }
                }

                if ($code !== null && $reference !== null) {
                    $key = ImportValues::codeKey($code).'|'.mb_strtolower($reference);

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already carries supplier %s\'s document "%s".', $seen[$key], $code, $reference);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'supplier_code' => $code,
                    'reference' => $reference,
                    'description' => $description,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $currency,
                    'fx_rate' => $rate === '' ? null : $rate,
                    'bill_on' => $billOn,
                    'due_on' => $dueOn,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                return $cells->result($values, $errors);
            },
        );

        // The one-opener rule, seen from the supplier's side: a trial balance already committed
        // with a `2110` line means the payables were opened as a control total, and this file would
        // add the same obligations a second time — per bill, this time, which is worse because the
        // tie-out would then find two populations on one dimension.
        $blocker = OpeningCoverage::blockedByTrialBalance(CutoverDataset::OpeningPayables);

        return $blocker === null
            ? $validation
            : new CutoverValidation($validation->rows, [...$validation->batchErrors, $blocker]);
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $code = (string) ($payload['supplier_code'] ?? '');

            $supplier = Supplier::query()->where('code', $code)->first();

            if ($supplier === null) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d names supplier "%s", which is no longer in the register — import the suppliers file first.',
                    $row->line_number,
                    $code,
                ));
            }

            $this->record->execute(
                supplierId: $supplier->id,
                amountMinor: (int) ($payload['amount_minor'] ?? 0),
                billOn: Carbon::parse((string) ($payload['bill_on'] ?? $batch->created_at?->toDateString())),
                dueOn: Carbon::parse((string) $payload['due_on']),
                legacyReference: (string) $payload['reference'],
                description: isset($payload['description']) ? (string) $payload['description'] : null,
                currencyCode: isset($payload['currency_code']) ? (string) $payload['currency_code'] : null,
                fxRate: isset($payload['fx_rate']) ? (string) $payload['fx_rate'] : null,
                actorId: $userId,
            );

            $applied++;
        }

        return $applied;
    }
}
