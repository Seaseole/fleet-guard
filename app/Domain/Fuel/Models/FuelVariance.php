<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelVarianceState;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §10.9's consumption variance: the trip burned more (or less) diesel than its distance and load
 * explain, and somebody has to say what they think of that.
 *
 * The row stores its **inputs** — distance, litres, tonnes, and the expected rate the company was
 * running at — next to the figures derived from them, because §5.5's rule is that a printed number
 * must be the number that was decided on. Re-deriving the percentage months later would use a
 * different expected rate and quietly change the story the acknowledgement was about.
 *
 * One row per trip: the variance is a judgement about a closed trip, and a second row would be a
 * second opinion on the same figures. `resolved` is the leaf; if a reversal moves the litres, the
 * row is re-evaluated only while it is still open.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property string $trip_id
 * @property int $distance_km
 * @property int $litres_x100
 * @property string $litres_per_100_10_2
 * @property string $expected_per_100_10_2
 * @property string $variance_pct_10_2
 * @property string $tonnes_carried_10_2
 * @property FuelVarianceState $state
 * @property string|null $acknowledgement_note
 * @property Carbon $detected_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelVariance extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'trip_id',
        'distance_km',
        'litres_x100',
        'litres_per_100_10_2',
        'expected_per_100_10_2',
        'variance_pct_10_2',
        'tonnes_carried_10_2',
        'state',
        'acknowledgement_note',
        'detected_at',
        'decided_by',
        'decided_at',
        'previous_state',
        'state_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'distance_km' => 'integer',
            'litres_x100' => 'integer',
            'litres_per_100_10_2' => 'decimal:2',
            'expected_per_100_10_2' => 'decimal:2',
            'variance_pct_10_2' => 'decimal:2',
            'tonnes_carried_10_2' => 'decimal:2',
            'state' => FuelVarianceState::class,
            'detected_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'state_changed_at' => 'datetime',
        ];
    }

    /** The column is `state`, not the trait's default `status`. */
    protected function stateAttribute(): string
    {
        return 'state';
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            FuelVarianceState::Open->value => [
                FuelVarianceState::Acknowledged->value,
                FuelVarianceState::Disputed->value,
                FuelVarianceState::Resolved->value,
            ],
            FuelVarianceState::Acknowledged->value => [
                FuelVarianceState::Disputed->value,
                FuelVarianceState::Resolved->value,
            ],
            FuelVarianceState::Disputed->value => [
                FuelVarianceState::Acknowledged->value,
                FuelVarianceState::Resolved->value,
            ],
            FuelVarianceState::Resolved->value => [],
        ];
    }

    /**
     * Every decision leaves the open state, and the table's CHECK insists a decided row carries the
     * sentence that decided it. Refusing here names the variance instead of failing on the row.
     *
     * @return list<string>
     */
    protected function transitionsRequiringReason(): array
    {
        return [
            FuelVarianceState::Acknowledged->value,
            FuelVarianceState::Disputed->value,
            FuelVarianceState::Resolved->value,
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function litresMajor(): string
    {
        return number_format($this->litres_x100 / 100, 2, '.', '');
    }

    /** Signed percentage for the screen: "+14.20%" or "-6.00%". */
    public function varianceLabel(): string
    {
        $value = (float) $this->variance_pct_10_2;

        return sprintf('%s%s%%', $value > 0 ? '+' : '', number_format($value, 2, '.', ''));
    }

    public function isOpen(): bool
    {
        return $this->state === FuelVarianceState::Open;
    }
}
