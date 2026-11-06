<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\SupplierPaymentMethod;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Money that left the till toward a supplier, and the rule copied from the receipt side:
 * **the payment is made, then it is applied.** `RecordSupplierPayment` posts `Dr 2110 / Cr the method's
 * cash account` for the whole amount and leaves the residue on `unapplied_amount`; the application rows
 * are what decide whether a bill is paid.
 *
 * There is no `draft`: a bank fact has no draft state, which is why the `PAY-` reference is allocated at
 * creation (the numbering decision applied one document further).
 *
 * @property string $id
 * @property int $company_id
 * @property string $number
 * @property string $status
 * @property int $supplier_id
 * @property Carbon $paid_on
 * @property SupplierPaymentMethod $method
 * @property string|null $reference
 * @property string $currency_code
 * @property int $amount
 * @property int $base_amount
 * @property int $unapplied_amount
 * @property int $unapplied_base
 * @property string|null $note
 * @property string|null $source_uuid
 * @property int|null $recorded_by
 * @property Carbon|null $recorded_at
 */
final class SupplierPayment extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'number', 'status', 'supplier_id', 'paid_on', 'method', 'reference',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount', 'unapplied_amount', 'unapplied_base',
        'note', 'source_uuid', 'recorded_by', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'supplier_id' => 'integer',
            'paid_on' => 'date',
            'method' => SupplierPaymentMethod::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'unapplied_amount' => 'integer',
            'unapplied_base' => 'integer',
            'recorded_by' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
