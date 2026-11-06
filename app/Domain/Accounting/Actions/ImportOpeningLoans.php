<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 0.5 — the opening financing book.
 *
 * One row is one facility the fleet was already paying for: the principal still owing, the rate it is
 * charged at, and the instalments still to come. Each row becomes one `disbursed` loan with a frozen
 * plan through {@see RecordOpeningLoan} — and **no posting**, because the drawdown is the old system's
 * money and the liability is the trial balance's `2210` line. What this file carries is the calendar
 * the 13-week forecast needs (the Phase 5 wording).
 *
 * The row names the parties the way the accountant's export does — a registration, a supplier code, a
 * bank account's name — and `validate()` preloads all three registers so an unknown one is a row error
 * beside the row rather than a crash on the last line of the file (the rule for the master files).
 *
 * Retrying is safe the same way every other opening file is: `reference` is the legacy loan number, it
 * is the natural key, and the key is looked up before anything else about the row is judged.
 */
final class ImportOpeningLoans implements CutoverDatasetHandler
{
    public function __construct(
        private readonly RecordOpeningLoan $record,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $truckIdByRegistration = [];
        $supplierIdByCode = [];
        $bankByName = [];

        foreach (Truck::query()->get(['id', 'registration_no']) as $truck) {
            $truckIdByRegistration[ImportValues::nameKey((string) $truck->registration_no)] = $truck->id;
        }

        foreach (Supplier::query()->get(['id', 'code']) as $supplier) {
            if ($supplier->code !== null && $supplier->code !== '') {
                $supplierIdByCode[ImportValues::codeKey($supplier->code)] = $supplier->id;
            }
        }

        foreach (BankAccount::query()->get(['id', 'name', 'gl_account_id']) as $bank) {
            $bankByName[ImportValues::nameKey((string) $bank->name)] = $bank;
        }

        $seen = [];

        $validation = CutoverRows::read(
            CutoverDataset::OpeningLoans,
            $contents,
            function (CutoverCells $cells) use ($truckIdByRegistration, $supplierIdByCode, $bankByName, $companyCurrency, &$seen): CutoverRowResult {
                $errors = [];

                [$reference, $error] = ImportValues::text($cells->get('reference'), 40, 'Legacy loan number', true);
                ImportValues::push($errors, $error);

                [$principalMinor, $error] = ImportValues::money($cells->get('principal'), $companyCurrency, 'Principal still owing');
                ImportValues::push($errors, $error);

                if ($principalMinor === null && $errors === []) {
                    $errors[] = 'Principal still owing is required — the trial balance carries the liability, and this row is the part of it still unpaid.';
                }

                if ($principalMinor !== null && $principalMinor <= 0) {
                    $errors[] = 'Principal must be greater than zero; a facility with nothing left is closed, and the design has a door for that.';
                }

                $rate = trim($cells->get('annual_rate_percent'));

                if ($rate === '') {
                    $errors[] = 'Annual rate is required — the instalment rows are frozen from it, and a guessed rate is a guessed debt.';
                } elseif (preg_match('/^\d{1,5}(\.\d{1,4})?$/', $rate) !== 1) {
                    $errors[] = 'Annual rate must be a percentage with at most 4 decimals (12.75 means 12.75 %).';
                } elseif ((float) $rate > 100) {
                    $errors[] = 'Annual rate must be a percentage, not a fraction — 12.75, not 0.1275.';
                }

                $rawFrequency = mb_strtolower(trim($cells->get('frequency')));
                $frequency = LoanFrequency::tryFrom($rawFrequency);

                if ($rawFrequency === '') {
                    $errors[] = 'Frequency is required — monthly or fortnightly, which is what decides the spacing of the plan.';
                } elseif ($frequency === null) {
                    $errors[] = sprintf('Frequency "%s" is neither monthly nor fortnightly.', $rawFrequency);
                }

                [$repaymentCount, $error] = ImportValues::count($cells->get('repayment_count'), 'Instalments remaining');
                ImportValues::push($errors, $error);

                if ($repaymentCount === null && $errors === []) {
                    $errors[] = 'Instalments remaining is required — the count is the term this book has left to project.';
                }

                if ($repaymentCount !== null && $repaymentCount < 1) {
                    $errors[] = 'Instalments remaining must be at least 1; a paid-off facility belongs to the close, not to the opening book.';
                }

                [$firstPaymentOn, $error] = ImportValues::date($cells->get('first_payment_on'), 'Next payment due', true);
                ImportValues::push($errors, $error);

                $registration = trim($cells->get('truck_registration_no'));

                if ($registration !== '' && ! isset($truckIdByRegistration[ImportValues::nameKey($registration)])) {
                    $errors[] = sprintf(
                        'Truck "%s" is not in the fleet. Import the trucks file first, or leave the column blank — this edge is '
                        .'the truck the money bought, not a truck the ledger must resolve.',
                        $registration,
                    );
                }

                $rawPurpose = mb_strtolower(trim($cells->get('purpose')));
                $purpose = $rawPurpose === '' ? LoanPurpose::Vehicle : LoanPurpose::tryFrom($rawPurpose);

                if ($rawPurpose !== '' && $purpose === null) {
                    $errors[] = sprintf('Purpose "%s" is not one of vehicle, equipment or working_capital.', $rawPurpose);
                }

                if ($purpose === LoanPurpose::WorkingCapital && $registration !== '') {
                    $errors[] = 'A working-capital facility names no truck — the column is the machine the money bought, so clear one of the two.';
                }

                $supplierCode = trim($cells->get('supplier_code'));

                if ($supplierCode !== '' && ! isset($supplierIdByCode[ImportValues::codeKey($supplierCode)])) {
                    $errors[] = sprintf(
                        'Supplier code "%s" is not in the register. Import the suppliers file first — the lender is who the '
                        .'instalments are paid to, and the design asks for a name on the paper.',
                        $supplierCode,
                    );
                }

                $bankName = trim($cells->get('bank_account_name'));

                if ($bankName !== '') {
                    $bank = $bankByName[ImportValues::nameKey($bankName)] ?? null;

                    if ($bank === null) {
                        $errors[] = sprintf(
                            'Bank account "%s" is not on the register. Open it on the banking screen first: it is where the '
                            .'instalments leave from, and this file cannot invent one.',
                            $bankName,
                        );
                    } elseif ($bank->gl_account_id === null) {
                        $errors[] = sprintf(
                            'Bank account "%s" is not linked to the ledger, so a payment drawn on it would have '
                            .'nowhere to leave. Link it on the banking screen before importing the facility.',
                            $bankName,
                        );
                    }
                }

                [$notes, $error] = ImportValues::text($cells->get('notes'), 500, 'Notes');
                ImportValues::push($errors, $error);

                if ($reference !== null) {
                    $key = mb_strtolower($reference);

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already carries facility "%s".', $seen[$key], $reference);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'reference' => $reference,
                    // The payload key is the file's own column name and the value is integer minor
                    // units — the convention, which is what the preview and the error report read
                    // when they decide to print a cell as money.
                    'principal' => $principalMinor,
                    'annual_rate_percent' => $rate === '' ? null : $rate,
                    'frequency' => $frequency?->value,
                    'repayment_count' => $repaymentCount,
                    'first_payment_on' => $firstPaymentOn,
                    'truck_registration_no' => $registration === '' ? null : $registration,
                    'supplier_code' => $supplierCode === '' ? null : $supplierCode,
                    'bank_account_name' => $bankName === '' ? null : $bankName,
                    'purpose' => $purpose?->value,
                    'notes' => $notes,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                return $cells->result($values, $errors);
            },
        );

        // No `OpeningCoverage` check: this file posts nothing, so it owns no account. `2210` stays the
        // trial balance's line and the schedule here is the only thing that says when it falls
        // due — which is why the picker's own sentence asks for this file first.
        return $validation;
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $registration = (string) ($payload['truck_registration_no'] ?? '');

            $truckId = null;

            if ($registration !== '') {
                $truck = Truck::query()->where('registration_no', $registration)->first();

                if ($truck === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Row %d names truck "%s", which is no longer in the fleet — import the trucks file first.',
                        $row->line_number,
                        $registration,
                    ));
                }

                $truckId = (int) $truck->id;
            }

            $supplierCode = (string) ($payload['supplier_code'] ?? '');
            $supplierId = null;

            if ($supplierCode !== '') {
                $supplier = Supplier::query()->where('code', $supplierCode)->first();

                if ($supplier === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Row %d names supplier "%s", which is no longer in the register — import the suppliers file first.',
                        $row->line_number,
                        $supplierCode,
                    ));
                }

                $supplierId = (int) $supplier->id;
            }

            $bankName = (string) ($payload['bank_account_name'] ?? '');
            $bankAccountId = null;

            if ($bankName !== '') {
                $bank = BankAccount::query()->where('name', $bankName)->first();

                if ($bank === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Row %d names bank account "%s", which is no longer on the register.',
                        $row->line_number,
                        $bankName,
                    ));
                }

                $bankAccountId = (int) $bank->id;
            }

            $frequency = LoanFrequency::tryFrom((string) ($payload['frequency'] ?? ''))
                ?? throw new InvalidArgumentException(sprintf(
                    'Row %d carries frequency "%s", which the schedule cannot space.',
                    $row->line_number,
                    (string) ($payload['frequency'] ?? ''),
                ));

            $this->record->execute(
                legacyReference: (string) $payload['reference'],
                principalMinor: (int) ($payload['principal'] ?? 0),
                annualRatePercent: (string) ($payload['annual_rate_percent'] ?? '0'),
                frequency: $frequency,
                repaymentCount: (int) ($payload['repayment_count'] ?? 0),
                nextPaymentOn: Carbon::parse((string) ($payload['first_payment_on'] ?? $batch->created_at?->toDateString())),
                purpose: LoanPurpose::tryFrom((string) ($payload['purpose'] ?? '')) ?? LoanPurpose::Vehicle,
                supplierId: $supplierId,
                truckId: $truckId,
                bankAccountId: $bankAccountId,
                notes: isset($payload['notes']) ? (string) $payload['notes'] : null,
                actorId: $userId,
            );

            $applied++;
        }

        return $applied;
    }
}
