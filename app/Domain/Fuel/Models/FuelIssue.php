<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 FUEL_ISSUE — fuel leaving a purchase into a truck, on a trip (§10.9's small machine).
 *
 * `litres_x100` is the spec's scale, so a pump's 45.32 L is the integer 4532 and sums in Sqlite and
 * MySQL agree to the last hundredth. `cost_base` is frozen at issue time from the purchase's own
 * rate (or, Phase 3b, the bulk tank's moving average): a cost read at report time would answer with
 * today's rate for money the ledger posted at yesterday's.
 *
 * The machine is the trait's; `recorded` is the inside-a-transaction state that exists so a row never
 * claims to have posted when its trip cost did not land. `reversed` is a leaf — §10.9's correction
 * is a reversal and a fresh issue, never an edit — and the reversal Action ships with the variance
 * slice. `reversalOf` links the pair, so the register can show both rows and the net.
 *
 * @property string $id
 * @property int $company_id
 * @property int $truck_id
 * @property int $fuel_tank_id
 * @property string|null $trip_id
 * @property int $litres_x100
 * @property int $odometer_km
 * @property string|null $purchase_id
 * @property int $cost_base
 * @property string $base_currency_code
 * @property FuelIssueSource $source
 * @property FuelIssueStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property string|null $exception_reason
 * @property string|null $trip_cost_id
 * @property string|null $reversal_of_id
 * @property string|null $source_uuid
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelIssue extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** ULID key (§2): the issue names itself as the trip cost's `source_id`. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'truck_id',
        'fuel_tank_id',
        'trip_id',
        'litres_x100',
        'odometer_km',
        'purchase_id',
        'cost_base',
        'base_currency_code',
        'source',
        'status',
        'previous_state',
        'state_changed_at',
        'exception_reason',
        'trip_cost_id',
        'reversal_of_id',
        'source_uuid',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'fuel_tank_id' => 'integer',
            'litres_x100' => 'integer',
            'odometer_km' => 'integer',
            'cost_base' => 'integer',
            'source' => FuelIssueSource::class,
            'status' => FuelIssueStatus::class,
            'state_changed_at' => 'datetime',
            'recorded_by' => 'integer',
        ];
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            FuelIssueStatus::Recorded->value => [FuelIssueStatus::Posted->value, FuelIssueStatus::Reversed->value],
            FuelIssueStatus::Posted->value => [FuelIssueStatus::Reversed->value],
            FuelIssueStatus::Reversed->value => [],
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /** @return BelongsTo<FuelTank, $this> */
    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id');
    }

    /** @return BelongsTo<FuelPurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(FuelPurchase::class, 'purchase_id');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<TripCost, $this> */
    public function tripCost(): BelongsTo
    {
        return $this->belongsTo(TripCost::class, 'trip_cost_id');
    }

    /** Litres as a major-unit string with two decimals — the screen's figure, not the row's. */
    public function litresMajor(): string
    {
        return number_format($this->litres_x100 / 100, 2, '.', '');
    }

    public function cost(): Money
    {
        return Money::ofMinor($this->cost_base, $this->base_currency_code);
    }
}
