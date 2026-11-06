<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Money\Money;
use DomainException;
use RuntimeException;

/**
 * Validates an opening trial balance into rows the ledger can take.
 *
 * Two kinds of failure, kept apart on purpose. A **row error** is about one spreadsheet line and
 * travels into the downloadable error report beside that line. A **batch error** is about the file
 * — a missing header, totals that do not balance, an exact re-upload of something already posted —
 * and is shown once above the table, because repeating it on every row would hide the row errors.
 *
 * Amounts are parsed with {@see Money::ofMajor()} so the screen's rule (grouping allowed, at most
 * the currency's decimal places, never a float) is the import's rule too.
 */
final class OpeningTrialBalanceValidator
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    public function validate(string $contents, string $currencyCode): CutoverValidation
    {
        $parsed = CutoverCsv::parse($contents, CutoverDataset::OpeningTrialBalance);

        if ($parsed->table === null) {
            return new CutoverValidation([], $parsed->errors);
        }

        $table = $parsed->table;
        $accounts = $this->chart->byCode();
        $covered = OpeningCoverage::openedByDetail();
        $positions = array_flip($table->headers);
        $seen = [];
        $rows = [];

        foreach ($table->rows as $input) {
            $cells = $input['cells'];
            $line = $input['line'];
            $errors = [];

            if (count($cells) !== count($table->headers)) {
                $errors[] = sprintf(
                    'Line %d has %d value(s) but the header has %d column(s).',
                    $line,
                    count($cells),
                    count($table->headers),
                );
            }

            $column = static function (string $name) use ($positions, $cells): string {
                $index = $positions[$name] ?? null;

                return $index === null ? '' : ($cells[$index] ?? '');
            };

            $accountCode = ltrim($column('account_code'), "'");
            $accountName = $column('account_name');
            $description = $column('description');
            $debitRaw = $column('debit');
            $creditRaw = $column('credit');

            [$debitMinor, $debitError] = $this->parseAmount($debitRaw, $currencyCode, 'Debit');
            [$creditMinor, $creditError] = $this->parseAmount($creditRaw, $currencyCode, 'Credit');

            if ($debitError !== null) {
                $errors[] = $debitError;
            }

            if ($creditError !== null) {
                $errors[] = $creditError;
            }

            if ($debitRaw === '' && $creditRaw === '') {
                $errors[] = 'Enter a debit or a credit.';
            } elseif ($debitMinor > 0 && $creditMinor > 0) {
                $errors[] = 'Enter either a debit or a credit, not both.';
            }

            $account = null;

            if ($accountCode === '') {
                $errors[] = 'Account code is required.';
            } else {
                $account = $accounts[$accountCode] ?? null;

                if ($account === null) {
                    $errors[] = "Account '{$accountCode}' is not in the chart of accounts.";
                } elseif (! $account->isPostable) {
                    $errors[] = "Account {$account->code} ({$account->name}) is a header account; post to a postable account.";
                } elseif (! $account->isActive) {
                    $errors[] = "Account {$account->code} ({$account->name}) is inactive.";
                } elseif (isset($covered[$accountCode])) {
                    // The two doors for one account, asked first: `1150` is either the accountant's
                    // control total or the sum of the imported open items — never both, or the opening
                    // balance sheet claims the customers owe the fleet twice and the tie-out cannot say
                    // which one is lying. This branch is deliberately before the
                    // dimension refusal below, because for a dimensional account both are true and the
                    // one that names the other file is the one the accountant can act on.
                    $errors[] = sprintf(
                        'Account %s (%s) was already opened by the %s intake (%s). Leave it out of the trial balance file: '
                        .'the party-level documents are the balance now, and a control total on top of them counts the same '
                        .'money twice.',
                        $account->code,
                        $account->name,
                        $covered[$accountCode]['dataset']->label(),
                        $covered[$accountCode]['filename'],
                    );
                } elseif ($account->requiredDimensions !== []) {
                    // The ledger would refuse this at commit (dimensionRequired), so say it here. An
                    // opening trial balance has no party columns: those accounts have their own
                    // intake now, and a control total posted beside a party-level document
                    // would owe the same money twice.
                    $errors[] = sprintf(
                        'Account %s (%s) requires %s detail, which an opening trial balance cannot carry. '
                        .'Import and apply the matching opening file (Opening receivables / Opening payables) instead.',
                        $account->code,
                        $account->name,
                        implode(' and ', $account->requiredDimensions),
                    );
                }

                if (isset($seen[$accountCode])) {
                    $errors[] = "Account {$accountCode} already appears on line {$seen[$accountCode]}.";
                } else {
                    $seen[$accountCode] = $line;
                }
            }

            $rows[] = new CutoverRowResult(
                lineNumber: $line,
                values: [
                    'account_code' => $accountCode === '' ? null : $accountCode,
                    'account_name' => $accountName === '' ? $account?->name : $accountName,
                    'debit_minor' => $debitMinor,
                    'credit_minor' => $creditMinor,
                    'description' => $description === '' ? null : $description,
                    'gl_account_id' => $this->postableId($account, $errors),
                ],
                errors: $errors,
            );
        }

        $validation = new CutoverValidation($rows, []);
        $batchErrors = [];

        $debit = $validation->totalDebit();
        $credit = $validation->totalCredit();

        if (($debit > 0 || $credit > 0) && $debit !== $credit) {
            $batchErrors[] = sprintf(
                'The trial balance does not balance: debits %s, credits %s, difference %s.',
                Money::ofMinor($debit, $currencyCode)->format(),
                Money::ofMinor($credit, $currencyCode)->format(),
                Money::ofMinor(abs($debit - $credit), $currencyCode)->format(),
            );
        }

        return new CutoverValidation($rows, $batchErrors);
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function parseAmount(string $raw, string $currencyCode, string $side): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [0, null];
        }

        try {
            $money = Money::ofMajor($raw, $currencyCode);
        } catch (DomainException|RuntimeException $exception) {
            return [0, "{$side}: {$exception->getMessage()}"];
        }

        if ($money->minorUnits === 0) {
            return [0, "{$side} must be greater than zero."];
        }

        return [$money->minorUnits, null];
    }

    /** @param  list<string>  $errors */
    private function postableId(?ChartAccount $account, array $errors): ?int
    {
        if ($account === null || $errors !== []) {
            return null;
        }

        return $account->id;
    }
}
