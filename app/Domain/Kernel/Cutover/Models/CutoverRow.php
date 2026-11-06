<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0.5 CUTOVER_ROW — one parsed line, kept whether it validated or not.
 *
 * Rejected rows are the deliverable, not waste: the error report is built from them, and the
 * accountant corrects the spreadsheet against the same line numbers. Commit reads only
 * `status = 'valid'`, so a best-effort amount on a rejected row can never reach the ledger.
 *
 * A master-data row keeps its normalised values in `payload`; a trial-balance row keeps them in the
 * typed columns below, because those are what the posting and reversal read. The two row CHECKs
 * treat a non-null payload as "this is not a journal line", which is the only discriminator needed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $cutover_batch_id
 * @property int $line_number
 * @property string|null $account_code
 * @property string|null $account_name
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string|null $description
 * @property int|null $gl_account_id
 * @property string $status
 * @property list<string>|null $errors
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CutoverRow extends Model
{
    use BelongsToCompany;

    protected $table = 'cutover_rows';

    /** @var list<string> */
    protected $fillable = [
        'cutover_batch_id',
        'line_number',
        'account_code',
        'account_name',
        'debit_minor',
        'credit_minor',
        'description',
        'gl_account_id',
        'status',
        'errors',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'gl_account_id' => 'integer',
            'errors' => 'array',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<CutoverBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CutoverBatch::class, 'cutover_batch_id');
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }

    /** @return list<string> */
    public function errorList(): array
    {
        return $this->errors ?? [];
    }
}
