<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §6.8 — one application of a supplier payment to one bill: the rows that are the **truth** of whether
 * a bill is paid, while `bills.balance_amount` is only their projection (§10.4's rule, copied to the
 * payable side so AP ageing stays a query).
 *
 * `countsTowardsTheBalance()` is the customer sibling's lesson (§20.33): a reversed row is evidence that
 * counts for nothing, and the balance tie-out must read through the same predicate the screen does.
 */
final class SupplierPaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'supplier_payment_id', 'bill_id',
        'currency_code', 'amount', 'base_currency_code', 'base_amount',
        'applied_on', 'settled', 'reversed', 'applied_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'applied_on' => 'date',
            'settled' => 'boolean',
            'reversed' => 'boolean',
            'applied_by' => 'integer',
        ];
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function countsTowardsTheBalance(): bool
    {
        return ! $this->reversed;
    }
}
