<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Invoicing\PaymentStatus;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * PAYMENT — money the customer sent, before anyone decided which invoice it was for.
 *
 * `unapplied_amount` is the column that makes the receipt bankable on its own day: a payment that no
 * invoice has claimed is still real money, and the ledger's credit to `2150 Unapplied customer cash` has to
 * balance against something on this row. Allocating it moves that money from `2150` to `1150`
 * without touching the receipt.
 *
 * The receipt number is allocated **at creation**, not at issue — there is no draft for money that has
 * already arrived, and the rule exists to stop drafts spending fiscal numbers. A void keeps the number,
 * because a bank fact that was recorded is not un-recorded by changing a status.
 *
 * @property string $id
 * @property int $company_id
 * @property string $number
 * @property PaymentStatus $status
 * @property int $customer_id
 * @property Carbon $received_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property string $base_currency_code
 * @property int $base_amount
 * @property int $unapplied_amount
 * @property int $unapplied_base
 * @property string|null $invoice_id
 * @property string|null $source_uuid
 * @property int|null $recorded_by
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 */
final class Payment extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'number', 'status', 'customer_id', 'received_on', 'method', 'reference',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount', 'unapplied_amount', 'unapplied_base',
        'invoice_id', 'source_uuid', 'recorded_by', 'void_reason', 'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => PaymentStatus::class,
            'customer_id' => 'integer',
            'received_on' => 'date',
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'unapplied_amount' => 'integer',
            'unapplied_base' => 'integer',
            'recorded_by' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** What the ledger's `2150` credit still carries for this receipt, in base minor units. */
    public function isFullyApplied(): bool
    {
        return $this->unapplied_base === 0;
    }
}
