<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a bulk tank's book, with the figures the tank held immediately afterwards (§4.3).
 *
 * Written only by {@see BulkTank::recordMovement()} inside the same transaction as the receipt's or
 * the issue's journal entry, so the book, the tank row and the ledger move together or not at all.
 *
 * @property string $id
 * @property int $company_id
 * @property int $bulk_tank_id
 * @property BulkTankMovementKind $kind
 * @property int $litres_delta_x100
 * @property int $value_delta_base
 * @property int $stock_litres_x100_after
 * @property int $stock_value_base_after
 * @property int $average_cost_per_litre_minor_after
 * @property Carbon $occurred_on
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BulkTankMovement extends Model
{
    use BelongsToCompany;

    /** ULID key (§2): a movement can be cited from outside the tank it belongs to. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'bulk_tank_id',
        'kind',
        'litres_delta_x100',
        'value_delta_base',
        'stock_litres_x100_after',
        'stock_value_base_after',
        'average_cost_per_litre_minor_after',
        'occurred_on',
        'source_type',
        'source_id',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'bulk_tank_id' => 'integer',
            'kind' => BulkTankMovementKind::class,
            'litres_delta_x100' => 'integer',
            'value_delta_base' => 'integer',
            'stock_litres_x100_after' => 'integer',
            'stock_value_base_after' => 'integer',
            'average_cost_per_litre_minor_after' => 'integer',
            'occurred_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<BulkTank, $this> */
    public function tank(): BelongsTo
    {
        return $this->belongsTo(BulkTank::class, 'bulk_tank_id');
    }

    public function litresDeltaMajor(): string
    {
        return number_format($this->litres_delta_x100 / 100, 2, '.', '');
    }

    public function valueDeltaMoney(): Money
    {
        return Money::ofMinor($this->value_delta_base, $this->baseCurrency());
    }

    private function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }
}
