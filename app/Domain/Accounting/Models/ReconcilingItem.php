<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\ReconcilingItemDirection;
use App\Domain\Accounting\ReconcilingItemKind;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One reconciling line between the bank's figure and the book's (Phase 5, slice 5d).
 * Amounts are positive; the sign is {@see ReconcilingItemDirection} alone, so the formula
 * `statement = book + Σ add − Σ sub` is arithmetic a reader can check with a calculator.
 *
 * @property string $id
 * @property int $company_id
 * @property string $reconciliation_id
 * @property ReconcilingItemKind $kind
 * @property ReconcilingItemDirection $direction
 * @property string $currency_code
 * @property int $amount
 * @property int $base_amount
 * @property Carbon|null $on_date
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $entered_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReconcilingItem extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'reconciling_items';

    protected $fillable = [
        'id',
        'reconciliation_id',
        'kind',
        'direction',
        'currency_code',
        'amount',
        'base_amount',
        'on_date',
        'reference',
        'note',
        'entered_by',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'kind' => ReconcilingItemKind::class,
            'direction' => ReconcilingItemDirection::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'on_date' => 'date',
            'entered_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class, 'reconciliation_id');
    }
}
