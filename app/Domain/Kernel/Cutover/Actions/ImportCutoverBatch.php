<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverHandlers;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Models\CutoverRow;
use App\Domain\Kernel\Cutover\Support\OpeningTrialBalanceValidator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §15.2 — validate an uploaded file and keep the result, errors included.
 *
 * Validation runs before the batch row exists so a rejected upload is still a row with a
 * reproducible error report: "the import error report is empty" is the phase's exit criterion, and
 * you cannot demonstrate an empty report without a report.
 *
 * The opening trial balance is validated by the ledger's own validator because its target is the
 * journal. Every master-data dataset is validated by the module that owns it, resolved through
 * {@see CutoverHandlers}; this Action only stores what came back. A checksum match against an
 * already-posted trial balance is a batch error, not a warning — posting the same balances twice
 * doubles every opening figure. Master files do not get that rule because applying one twice is an
 * update by natural key, not a second set of records.
 */
final class ImportCutoverBatch
{
    public function __construct(
        private readonly OpeningTrialBalanceValidator $openingBalances,
        private readonly CutoverHandlers $handlers,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        CutoverDataset $dataset,
        string $contents,
        ?string $filename,
        int $userId,
    ): CutoverBatch {
        $company = CurrentCompany::model();
        $currency = $company->base_currency_code;

        $validation = $dataset === CutoverDataset::OpeningTrialBalance
            ? $this->openingBalances->validate($contents, $currency)
            : $this->handlers->for($dataset)->validate($contents, $currency);

        $checksum = hash('sha256', $contents);
        $batchErrors = $validation->batchErrors;

        if ($dataset === CutoverDataset::OpeningTrialBalance) {
            $postedDuplicate = CutoverBatch::query()
                ->where('dataset', $dataset->value)
                ->where('checksum', $checksum)
                ->where('status', CutoverBatchStatus::Posted)
                ->first();

            if ($postedDuplicate !== null) {
                $batchErrors[] = sprintf(
                    'This exact file was already posted as batch #%d. Reverse that batch before uploading the same balances again.',
                    $postedDuplicate->id,
                );
            }
        }

        $status = $validation->isValid() && $batchErrors === []
            ? CutoverBatchStatus::Validated
            : CutoverBatchStatus::Rejected;

        $isTrialBalance = $dataset === CutoverDataset::OpeningTrialBalance;

        $batch = DB::transaction(function () use (
            $dataset,
            $isTrialBalance,
            $filename,
            $userId,
            $validation,
            $batchErrors,
            $status,
            $checksum,
            $currency,
        ): CutoverBatch {
            $batch = CutoverBatch::create([
                'public_id' => (string) Str::ulid(),
                'dataset' => $dataset,
                'status' => $status,
                'original_filename' => $filename === null ? null : Str::limit($filename, 255, ''),
                'checksum' => $checksum,
                'currency_code' => $currency,
                'row_count' => count($validation->rows),
                'valid_row_count' => $validation->validRowCount(),
                'error_row_count' => $validation->errorRowCount(),
                'total_debit' => $validation->totalDebit(),
                'total_credit' => $validation->totalCredit(),
                'batch_errors' => $batchErrors === [] ? null : array_values($batchErrors),
                'posting_event_id' => (string) Str::ulid(),
                'reversal_event_id' => (string) Str::ulid(),
                'created_by' => $userId,
            ]);

            foreach ($validation->rows as $row) {
                CutoverRow::create([
                    'cutover_batch_id' => $batch->id,
                    'line_number' => $row->lineNumber,
                    'account_code' => $isTrialBalance ? $row->value('account_code') : null,
                    'account_name' => $isTrialBalance ? $row->value('account_name') : null,
                    'debit_minor' => $isTrialBalance ? (int) $row->value('debit_minor') : 0,
                    'credit_minor' => $isTrialBalance ? (int) $row->value('credit_minor') : 0,
                    'description' => $isTrialBalance ? $row->value('description') : null,
                    'gl_account_id' => $isTrialBalance ? $row->value('gl_account_id') : null,
                    'status' => $row->isValid() ? 'valid' : 'error',
                    'errors' => $row->isValid() ? null : array_values($row->errors),
                    // The payload is the master-data row; a trial balance keeps its typed columns
                    // instead, so the two row CHECKs continue to defend the ledger's shape.
                    'payload' => $isTrialBalance ? null : $row->values,
                ]);
            }

            return $batch;
        });

        $this->audit->operational(
            'cutover_batch',
            $batch->public_id,
            null,
            $status->value,
            null,
            [
                'dataset' => $dataset->value,
                'filename' => $batch->original_filename,
                'rows' => $batch->row_count,
                'errors' => $batch->error_row_count,
                'batch_errors' => $batchErrors,
            ],
            actorId: $userId,
        );

        return $batch;
    }
}
