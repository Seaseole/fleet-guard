<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\WorkOrderLineKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * WORK_ORDER_LINE — one cost inside a work order.
 *
 * The row is deliberately ignorant of posting: phase 3d records what the workshop spent, and the
 * three posting paths the design names (parts issue, timesheet, supplier bill) each arrive in their own
 * slice with their own ledger side. What this row owns is the arithmetic — quantity × unit cost,
 * rounded once — and the frozen base figure the work order totals.
 *
 * @property int $id
 * @property int $company_id
 * @property string $work_order_id
 * @property WorkOrderLineKind $kind
 * @property string $description
 * @property int $quantity_x100
 * @property int $unit_cost_minor
 * @property string $currency_code
 * @property int $base_amount
 * @property int|null $part_id
 * @property bool $capital_candidate
 */
final class WorkOrderLine extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'work_order_id',
        'kind',
        'description',
        'quantity_x100',
        'unit_cost_minor',
        'currency_code',
        'base_amount',
        'part_id',
        'capital_candidate',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'kind' => WorkOrderLineKind::class,
            'quantity_x100' => 'integer',
            'unit_cost_minor' => 'integer',
            'base_amount' => 'integer',
            'part_id' => 'integer',
            'capital_candidate' => 'boolean',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->base_amount, $this->currency_code);
    }

    /** "2.50 hours", "4.00 units" — the quantity as the form and the register print it. */
    public function quantityLabel(): string
    {
        return number_format($this->quantity_x100 / 100, 2, '.', '').' '.$this->kind->unit();
    }
}
