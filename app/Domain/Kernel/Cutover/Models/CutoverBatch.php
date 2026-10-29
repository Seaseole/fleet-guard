<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Models;

use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Policies\CutoverBatchPolicy;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §15.2 Phase 0.5 CUTOVER_BATCH — one validated legacy upload and its life through the rehearsal.
 *
 * The row is written by validation, not by the commit: a rejected file keeps its rows and error
 * report, so the accountant can fix the spreadsheet from the same line numbers the screen showed.
 * `posting_event_id` and `reversal_event_id` exist from import time because they are the ledger's
 * replay keys — a commit that posts and then dies is retried with the same triple, not posted twice.
 *
 * A master-data batch follows the same two phases but ends at `applied`: the module handler upserts
 * by natural key inside one transaction, so an interrupted apply leaves nothing behind and the same
 * batch can be retried. `applied_at`/`applied_by` record the Owner who did it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $public_id
 * @property CutoverDataset $dataset
 * @property CutoverBatchStatus $status
 * @property string|null $original_filename
 * @property string $checksum
 * @property string $currency_code
 * @property int $row_count
 * @property int $valid_row_count
 * @property int $error_row_count
 * @property int $total_debit
 * @property int $total_credit
 * @property list<string>|null $batch_errors
 * @property string $posting_event_id
 * @property string $reversal_event_id
 * @property string|null $journal_entry_id
 * @property string|null $reversal_entry_id
 * @property Carbon|null $posted_on
 * @property Carbon|null $posted_at
 * @property Carbon|null $reversed_at
 * @property Carbon|null $applied_at
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property int|null $reversed_by
 * @property int|null $applied_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(CutoverBatchPolicy::class)]
final class CutoverBatch extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    protected $table = 'cutover_batches';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'dataset',
        'status',
        'original_filename',
        'checksum',
        'currency_code',
        'row_count',
        'valid_row_count',
        'error_row_count',
        'total_debit',
        'total_credit',
        'batch_errors',
        'posting_event_id',
        'reversal_event_id',
        'journal_entry_id',
        'reversal_entry_id',
        'posted_on',
        'posted_at',
        'reversed_at',
        'applied_at',
        'created_by',
        'posted_by',
        'reversed_by',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'dataset' => CutoverDataset::class,
            'status' => CutoverBatchStatus::class,
            'batch_errors' => 'array',
            'row_count' => 'integer',
            'valid_row_count' => 'integer',
            'error_row_count' => 'integer',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'posted_on' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return HasMany<CutoverRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(CutoverRow::class)->orderBy('line_number');
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->batch_errors ?? [];
    }

    public function isReadyToPost(): bool
    {
        return $this->dataset === CutoverDataset::OpeningTrialBalance
            && $this->status === CutoverBatchStatus::Validated
            && $this->error_row_count === 0
            && $this->batch_errors === null;
    }

    public function isReadyToApply(): bool
    {
        return $this->dataset->isMasterData()
            && $this->status === CutoverBatchStatus::Validated
            && $this->error_row_count === 0
            && $this->batch_errors === null;
    }
}
