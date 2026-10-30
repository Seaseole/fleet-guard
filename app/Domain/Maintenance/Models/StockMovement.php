<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\StockMovementKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a part's book, with the figures the shelf held immediately afterwards (§4.3).
 *
 * Written only by {@see Part::recordMovement()} in the same transaction as the movement's journal
 * entry, so the row, the part and the ledger move together or not at all.
 *
 * @property string $id
 * @property int $company_id
 * @property int $part_id
 * @property StockMovementKind $kind
 * @property int $quantity_delta_x100
 * @property int $value_delta_base
 * @property int $quantity_after_x100
 * @property int $value_after_base
 * @property int $average_cost_minor_after
 * @property string|null $work_order_id
 * @property int|null $unit_cost_minor
 * @property Carbon $moved_on
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockMovement extends Model
{
    use BelongsToCompany;

    /** ULID key (§2): a movement can be cited from the ledger entry it posted. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'part_id',
        'kind',
        'quantity_delta_x100',
        'value_delta_base',
        'quantity_after_x100',
        'value_after_base',
        'average_cost_minor_after',
        'work_order_id',
        'unit_cost_minor',
        'moved_on',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'part_id' => 'integer',
            'kind' => StockMovementKind::class,
            'quantity_delta_x100' => 'integer',
            'value_delta_base' => 'integer',
            'quantity_after_x100' => 'integer',
            'value_after_base' => 'integer',
            'average_cost_minor_after' => 'integer',
            'unit_cost_minor' => 'integer',
            'moved_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function quantityDeltaLabel(): string
    {
        $units = number_format(abs($this->quantity_delta_x100) / 100, 2, '.', '');

        return ($this->quantity_delta_x100 < 0 ? '−' : '+').$units;
    }

    public function valueDeltaMoney(): Money
    {
        return Money::ofMinor(abs($this->value_delta_base), CurrentCompany::model()->base_currency_code);
    }
}
