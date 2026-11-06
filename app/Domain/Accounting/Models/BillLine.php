<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\BillCategory;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * BILL_LINE — one line of a supplier bill, and the leg of the fleet its cost belongs to.
 *
 * The line carries `truck_id`/`trip_id` because the expense accounts their categories map to demand
 * them (the masks: 5100 truck+trip, 5310/5300 truck). A line that could not name its leg would
 * post `PostingRejected::dimensionRequired` — which is the guard doing its job, not a nuisance: fuel
 * that loses its truck lands in overheads and per-trip margin stops being true.
 *
 * `expense_account_code` is copied at write time from {@see BillCategory::accountCode()}, the same
 * statement-of-intent decision `invoice_lines.revenue_account_code` records.
 *
 * @property string $id
 * @property int $company_id
 * @property string $bill_id
 * @property string $description
 * @property BillCategory $category
 * @property string $expense_account_code
 * @property int|null $truck_id
 * @property string|null $trip_id
 * @property string|null $work_order_id
 * @property int $net_amount
 * @property int $net_base
 * @property string $currency_code
 * @property string|null $tax_rate_id
 * @property int $tax_amount
 * @property int $tax_base
 * @property int $position
 * @property Carbon|null $released_at
 */
final class BillLine extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'bill_id', 'description', 'category', 'expense_account_code',
        'truck_id', 'trip_id', 'work_order_id',
        'net_amount', 'net_base', 'currency_code',
        'tax_rate_id', 'tax_amount', 'tax_base', 'position',
        // Set by a void; `VoidBill` is the only writer, so it stays out of a screen's mass assignment.
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'category' => BillCategory::class,
            'truck_id' => 'integer',
            'net_amount' => 'integer',
            'net_base' => 'integer',
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'position' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
