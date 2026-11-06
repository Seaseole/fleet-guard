<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Cutover\Support\OpeningCoverage;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 0.5 — the unreconciled advances file.
 *
 * One row is one legacy slip: cash a driver was given before cutover and has not answered for. Each
 * row becomes one `Dr 1160 / Cr 3010` entry through {@see RecordOpeningAdvance}, with the driver on
 * the line because the mask demands it and an account that cannot name its debtor is an account
 * the trial balance has to refuse.
 *
 * The employee number is the join, not the driver id: the accountant's export knows payroll numbers,
 * and a driver *is* an employee with papers (the drivers file). So a row is refused when the
 * number is not on the register **or** when the employee has no driver record yet — both are "import
 * the other file first", which is the same sentence the party-detail files say about a customer code.
 *
 * `validate()` preloads both registers and writes nothing; `apply()` is safe to retry because the
 * slip number is the natural key of the entry (the replay guard), so a batch that dies on row 300
 * has produced 299 receivables and no second one for any of them.
 */
final class ImportOpeningDriverAdvances implements CutoverDatasetHandler
{
    public function __construct(
        private readonly RecordOpeningAdvance $record,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $driverIdByEmployee = [];
        $employeeNos = [];

        foreach (Employee::query()->get(['id', 'employee_no']) as $employee) {
            $employeeNos[ImportValues::codeKey((string) $employee->employee_no)] = true;
        }

        foreach (Driver::query()->with('employee:id,employee_no')->get(['id', 'employee_id']) as $driver) {
            $employee = $driver->employee;

            if ($employee !== null) {
                $driverIdByEmployee[ImportValues::codeKey((string) $employee->employee_no)] = $driver->id;
            }
        }

        $seen = [];

        $validation = CutoverRows::read(
            CutoverDataset::OpeningDriverAdvances,
            $contents,
            function (CutoverCells $cells) use ($employeeNos, $driverIdByEmployee, $companyCurrency, &$seen): CutoverRowResult {
                $errors = [];

                [$employeeNo, $error] = ImportValues::text($cells->get('employee_no'), 24, 'Employee number', true);
                ImportValues::push($errors, $error);

                [$slip, $error] = ImportValues::text($cells->get('reference'), 40, 'Legacy slip number', true);
                ImportValues::push($errors, $error);

                [$amountMinor, $error] = ImportValues::money($cells->get('amount'), $companyCurrency, 'Amount');
                ImportValues::push($errors, $error);

                if ($amountMinor === null && $errors === []) {
                    $errors[] = 'Amount is required — an advance nobody can put a figure on is a query for the driver, not a row.';
                }

                if ($amountMinor !== null && $amountMinor <= 0) {
                    $errors[] = 'Amount must be greater than zero; money the driver handed back is a settlement, and the desk is where that belongs.';
                }

                [$advancedOn, $error] = ImportValues::date($cells->get('advanced_on'), 'Paid on', true);
                ImportValues::push($errors, $error);

                [$note, $error] = ImportValues::text($cells->get('note'), 250, 'Note');
                ImportValues::push($errors, $error);

                if ($employeeNo !== null && ! isset($employeeNos[ImportValues::codeKey($employeeNo)])) {
                    $errors[] = sprintf(
                        'Employee number "%s" is not on the register. Import the employees file first — an advance is money a '
                        .'named person holds, and the ledger cannot open a receivable against nobody.',
                        $employeeNo,
                    );
                } elseif ($employeeNo !== null && ! isset($driverIdByEmployee[ImportValues::codeKey($employeeNo)])) {
                    $errors[] = sprintf(
                        '%s is an employee but has no driver record, so this slip has no driver dimension. Import the drivers '
                        .'file, or enrol them on the people screen — 1160 demands a driver.',
                        $employeeNo,
                    );
                }

                if ($employeeNo !== null && $slip !== null) {
                    $key = ImportValues::codeKey($employeeNo).'|'.mb_strtolower($slip);

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already carries %s\'s slip "%s".', $seen[$key], $employeeNo, $slip);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'employee_no' => $employeeNo,
                    'reference' => $slip,
                    // The payload key is the file's own column name and the value is integer minor
                    // units — the convention, which is what the preview and the error report look
                    // up when they decide to print a cell as money.
                    'amount' => $amountMinor,
                    'advanced_on' => $advancedOn,
                    'note' => $note,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                return $cells->result($values, $errors);
            },
        );

        // One account, one opener: the trial balance cannot carry a `1160` control total once
        // this file has, and the validator refuses the line before a batch is applied to find it out.
        $blocker = OpeningCoverage::blockedByTrialBalance(CutoverDataset::OpeningDriverAdvances);

        return $blocker === null
            ? $validation
            : new CutoverValidation($validation->rows, [...$validation->batchErrors, $blocker]);
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $employeeNo = (string) ($payload['employee_no'] ?? '');

            $employee = Employee::query()->where('employee_no', $employeeNo)->first();
            $driver = $employee === null ? null : Driver::query()->where('employee_id', $employee->id)->first();

            if ($driver === null) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d names employee "%s", which no longer has a driver record — import the drivers file first.',
                    $row->line_number,
                    $employeeNo,
                ));
            }

            $paidOn = Carbon::parse((string) ($payload['advanced_on'] ?? $batch->created_at?->toDateString()));

            $this->record->execute(
                driverId: (int) $driver->id,
                amountMinor: (int) ($payload['amount'] ?? 0),
                advancedOn: $paidOn,
                legacySlipNumber: (string) $payload['reference'],
                note: isset($payload['note']) ? (string) $payload['note'] : null,
                actorId: $userId,
                // The slip keeps its own day; the entry is struck on cutover day, exactly as the
                // documents are. Posting an opening balance backwards would reach into a period the
                // books may already have closed, and the old system's bank is not this year's cash.
                postedOn: Carbon::now(),
            );

            $applied++;
        }

        return $applied;
    }
}
