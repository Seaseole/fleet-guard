<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Cutover\Support\OpeningCoverage;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 0.5 — the opening receivables file.
 *
 * Each row is one legacy open item, and each row becomes one invoice through
 * {@see RecordOpeningReceivable}. The file is not a list of balances per customer: it is a list of
 * **documents**, because that is the only shape whose ageing, statement and tie-out readings can be
 * checked against the accountant's schedule line by line.
 *
 * `validate()` writes nothing and preloads the customer register, so a code that has not been
 * imported yet is a row error beside the row rather than an exception on the last line of a
 * thousand — the same lesson learned from the master files.
 *
 * `apply()` is safe to retry because every row carries the legacy document number and the Action
 * keys its idempotency on it: a batch that dies on row 400 has produced 399 invoices and no second
 * one for any of them.
 */
final class ImportOpeningReceivables implements CutoverDatasetHandler
{
    public function __construct(
        private readonly RecordOpeningReceivable $record,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $byCode = [];

        foreach (Customer::query()->get(['id', 'code']) as $customer) {
            if ($customer->code !== null && $customer->code !== '') {
                $byCode[ImportValues::codeKey($customer->code)] = $customer->id;
            }
        }

        $seen = [];

        $validation = CutoverRows::read(
            CutoverDataset::OpeningReceivables,
            $contents,
            function (CutoverCells $cells) use ($byCode, $companyCurrency, &$seen): CutoverRowResult {
                $errors = [];

                [$code, $error] = ImportValues::code($cells->get('customer_code'), 24, 'Customer code', true);
                ImportValues::push($errors, $error);

                [$reference, $error] = ImportValues::text($cells->get('reference'), 40, 'Legacy document number', true);
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
                    $errors[] = 'Amount is required — an open item with no figure is not a row, it is a note.';
                }

                if ($amountMinor !== null && $amountMinor <= 0) {
                    $errors[] = 'Amount must be greater than zero; a customer owing nothing belongs in nobody\'s register.';
                }

                [$dueOn, $error] = ImportValues::date($cells->get('due_on'), 'Due date', true);
                ImportValues::push($errors, $error);

                [$issuedOn, $error] = ImportValues::date($cells->get('issued_on'), 'Issue date');
                ImportValues::push($errors, $error);

                // The rate is only asked of a foreign row, and only in the shape the ledger can freeze:
                // at most 8 decimals, positive, never a float read back out of a cell.
                $rate = trim($cells->get('fx_rate'));

                if ($currency !== $companyCurrency && $rate === '') {
                    $errors[] = sprintf(
                        'A %s open item needs the rate it is worth in %s on cutover day, or the balance must be given in %s.',
                        $currency,
                        $companyCurrency,
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
                        'Customer code "%s" is not in the register. Import the customers file first — an opening balance '
                        .'has to name a debtor the ageing report can address.',
                        $code,
                    );
                }

                if ($dueOn !== null) {
                    // An open item with no `issued_on` is dated the day it fell due, or today when it
                    // is still in the future: `invoices_dated_sensibly` refuses a document that
                    // predates nothing, and an overdue legacy invoice is dated by its own paper.
                    $issuedOn ??= min($dueOn, Carbon::now()->toDateString());

                    if (Carbon::parse($issuedOn)->gt(Carbon::parse($dueOn))) {
                        $errors[] = 'Issue date must be on or before the due date.';
                    }
                }

                if ($code !== null && $reference !== null) {
                    $key = ImportValues::codeKey($code).'|'.mb_strtolower($reference);

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already carries customer %s\'s document "%s".', $seen[$key], $code, $reference);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'customer_code' => $code,
                    'reference' => $reference,
                    'description' => $description,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $currency,
                    'fx_rate' => $rate === '' ? null : $rate,
                    'issued_on' => $issuedOn,
                    'due_on' => $dueOn,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                return $cells->result($values, $errors);
            },
        );

        // One account, one opener: a committed trial balance that already carries `1150`
        // makes this file a second claim on the same money, so the whole batch is refused rather
        // than applied and found out by the tie-out a week later.
        $blocker = OpeningCoverage::blockedByTrialBalance(CutoverDataset::OpeningReceivables);

        return $blocker === null
            ? $validation
            : new CutoverValidation($validation->rows, [...$validation->batchErrors, $blocker]);
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;
        $baseCurrency = CurrentCompany::model()->base_currency_code;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $code = (string) ($payload['customer_code'] ?? '');

            $customer = Customer::query()->where('code', $code)->first();

            if ($customer === null) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d names customer "%s", which is no longer in the register — import the customers file first.',
                    $row->line_number,
                    $code,
                ));
            }

            $currency = (string) ($payload['currency_code'] ?? $baseCurrency);

            $this->record->execute(
                customerId: $customer->id,
                amountMinor: (int) ($payload['amount_minor'] ?? 0),
                issuedOn: Carbon::parse((string) ($payload['issued_on'] ?? $batch->created_at?->toDateString())),
                dueOn: Carbon::parse((string) $payload['due_on']),
                legacyReference: (string) $payload['reference'],
                description: isset($payload['description']) ? (string) $payload['description'] : null,
                currencyCode: $currency,
                fxRate: isset($payload['fx_rate']) ? (string) $payload['fx_rate'] : null,
                actorId: $userId,
            );

            $applied++;
        }

        return $applied;
    }
}
