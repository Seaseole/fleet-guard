<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §4.2 PAYMENT_ALLOCATION — the act of saying which invoice a receipt was for.
 *
 * Its own row (and its own ledger seam) because the design refuses to "adjust" an invoice when a customer
 * overpays: the residue stays in `2150` until a later allocation finds it, so one receipt can settle three
 * invoices and one invoice can be settled by four receipts (§10.4).
 *
 * `settled` marks the allocation that **closed** the invoice — the one §5.5's residue rule absorbed the
 * final ±1 minor unit into — so a zeroed balance can be told from a partly paid one without re-running the
 * FIFO walk. Currency-native and base amounts are both stored: re-deriving a historical figure from
 * `base ÷ rate` is §5.3's rewritten history.
 *
 * @property string $id
 * @property int $company_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property string $currency_code
 * @property int $amount
 * @property string $base_currency_code
 * @property int $base_amount
 * @property bool $has_fx_difference
 * @property int $fx_difference_base
 * @property string|null $fx_difference_side
 * @property Carbon $allocated_on
 * @property bool $settled
 * @property bool $reversed
 * @property string|null $reversal_reason
 * @property Carbon|null $reversed_at
 * @property int|null $allocated_by
 */
final class PaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'payment_id', 'invoice_id',
        'currency_code', 'amount', 'base_currency_code', 'base_amount',
        'has_fx_difference', 'fx_difference_base', 'fx_difference_side',
        'allocated_on', 'settled', 'reversed', 'reversal_reason', 'reversed_at', 'allocated_by',
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
            'has_fx_difference' => 'boolean',
            'fx_difference_base' => 'integer',
            'allocated_on' => 'date',
            'settled' => 'boolean',
            'reversed' => 'boolean',
            'reversed_at' => 'datetime',
            'allocated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** A reversed allocation still exists as evidence, but counts as nothing to the projection (§10.4). */
    public function countsTowardsTheBalance(): bool
    {
        return ! $this->reversed;
    }
}
