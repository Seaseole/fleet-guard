<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Policies\ReconciliationPolicy;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A bank reconciliation run (Phase 5, slice 5d): one linked register account, one
 * statement date, the bank's own closing figure, and the reconciling items that walk the book
 * to it. The run is a control record, not a posting — it changes no ledger row, and approving
 * it certifies that two already-existing figures agree.
 *
 * `book_base_amount` is frozen at start and re-frozen at approval: the approval is the moment a
 * person signed *those* figures, and a posting into the same day afterwards must not rewrite
 * what was signed (the spirit applied one layer up). Open runs therefore show the live book
 * through {@see ReconciliationMath}; approved runs show theirs.
 *
 * @property string $id
 * @property int $company_id
 * @property string $number
 * @property ReconciliationStatus $status
 * @property int $bank_account_id
 * @property Carbon $statement_on
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_amount
 * @property int $book_base_amount
 * @property string|null $note
 * @property int|null $started_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(ReconciliationPolicy::class)]
final class Reconciliation extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'reconciliations';

    protected $fillable = [
        'id',
        'number',
        'status',
        'bank_account_id',
        'statement_on',
        'currency_code',
        'amount',
        'fx_rate',
        'fx_rate_source',
        'fx_rate_date',
        'fx_rate_ref',
        'base_currency_code',
        'base_amount',
        'book_base_amount',
        'note',
        'started_by',
        'approved_by',
        'approved_at',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'bank_account_id' => 'integer',
            'status' => ReconciliationStatus::class,
            'statement_on' => 'date',
            'fx_rate_date' => 'date',
            'fx_rate' => 'string',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'book_base_amount' => 'integer',
            'started_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<ReconcilingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReconcilingItem::class, 'reconciliation_id')->orderBy('on_date')->orderBy('created_at');
    }
}
