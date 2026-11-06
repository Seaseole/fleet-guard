<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Policies\TripPolicy;
use App\Domain\Operations\TripStatus;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * TRIP — the hub every other module hangs from.
 *
 * The truck, trailer, driver and supercargo are stored as **ids, not relations**: naming another
 * module's model here would break D5, and the read doors
 * ({@see FleetDirectory}, {@see DriverDirectory})
 * already return exactly the fields a trip may need — label, status, capacity, papers. A trip that
 * joined to `Truck` would quietly make Fleet a dependency of every trip query.
 *
 * `route_id` is copied from the job at planning rather than read through it. The profitability and
 * Phase 8's corridor reports group on the route the trip actually ran, and a master-data edit to the
 * job must not rewrite history.
 *
 * `odometer_start_km` / `odometer_end_km` are the trip's own facts; the truck's projection is a
 * separate row written through Fleet's `RecordOdometerReading`, so the two cannot disagree by
 * construction.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_no
 * @property string $job_id
 * @property int $route_id
 * @property int $truck_id
 * @property int|null $trailer_id
 * @property int $driver_id
 * @property int|null $supercargo_employee_id
 * @property TripStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property Carbon|null $planned_departure_at
 * @property Carbon|null $departed_at
 * @property Carbon|null $arrived_at
 * @property Carbon|null $completed_at
 * @property int|null $odometer_start_km
 * @property int|null $odometer_end_km
 * @property string|float|null $engine_hours_start_10_2
 * @property string|float|null $distance_km_10_2
 * @property array<string, mixed>|null $exception_flags
 * @property bool $costs_frozen
 * @property int $revenue_base
 * @property int $fuel_cost_base
 * @property int $maintenance_cost_base
 * @property int $border_cost_base
 * @property int $driver_cost_base
 * @property int $other_direct_cost_base
 * @property int $direct_cost_base
 * @property int $contribution_base
 * @property Carbon|null $ledger_posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(TripPolicy::class)]
final class Trip extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** @var list<string> */
    protected $fillable = [
        'trip_no',
        'job_id',
        'route_id',
        'truck_id',
        'trailer_id',
        'driver_id',
        'supercargo_employee_id',
        'planned_departure_at',
        'departed_at',
        'arrived_at',
        'completed_at',
        'odometer_start_km',
        'odometer_end_km',
        'engine_hours_start_10_2',
        'distance_km_10_2',
        'exception_flags',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'route_id' => 'integer',
            'truck_id' => 'integer',
            'trailer_id' => 'integer',
            'driver_id' => 'integer',
            'supercargo_employee_id' => 'integer',
            'status' => TripStatus::class,
            'state_changed_at' => 'datetime',
            'planned_departure_at' => 'datetime',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'odometer_start_km' => 'integer',
            'odometer_end_km' => 'integer',
            'engine_hours_start_10_2' => 'float',
            'distance_km_10_2' => 'float',
            'exception_flags' => 'array',
            'costs_frozen' => 'boolean',
            // The projection. `contribution_base` is signed (a trip can lose money); the five
            // buckets are debits and the CHECK refuses a negative one, so they are unsigned here too.
            'revenue_base' => 'integer',
            'fuel_cost_base' => 'integer',
            'maintenance_cost_base' => 'integer',
            'border_cost_base' => 'integer',
            'driver_cost_base' => 'integer',
            'other_direct_cost_base' => 'integer',
            'direct_cost_base' => 'integer',
            'contribution_base' => 'integer',
            'ledger_posted_at' => 'datetime',
        ];
    }

    /**
     * The diagram, transcribed.
     *
     * Two edges the prose allows but this table does not: nothing returns to `draft` (a wrong draft is
     * deleted or planned again as a new trip, so a trip never loses its identity), and `closed` is
     * reachable only from `completed` — the `reopened → completed` rather than `reopened → closed`
     * is what makes a re-run checklist mandatory after a re-open.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            TripStatus::Draft->value => [TripStatus::Planned->value],
            TripStatus::Planned->value => [TripStatus::Assigned->value, TripStatus::OnHold->value, TripStatus::Cancelled->value],
            TripStatus::Assigned->value => [TripStatus::Loaded->value, TripStatus::Departed->value, TripStatus::OnHold->value, TripStatus::Cancelled->value],
            TripStatus::Loaded->value => [TripStatus::Departed->value],
            TripStatus::OnHold->value => [TripStatus::Planned->value],
            TripStatus::Departed->value => [TripStatus::InTransit->value, TripStatus::Aborted->value],
            TripStatus::InTransit->value => [TripStatus::AtBorder->value, TripStatus::Arrived->value],
            TripStatus::AtBorder->value => [TripStatus::InTransit->value],
            TripStatus::Arrived->value => [TripStatus::PodPending->value],
            TripStatus::PodPending->value => [TripStatus::Delivered->value],
            TripStatus::Delivered->value => [TripStatus::Completed->value],
            TripStatus::Completed->value => [TripStatus::Closed->value],
            TripStatus::Closed->value => [TripStatus::Reopened->value],
            TripStatus::Reopened->value => [TripStatus::Completed->value],
            TripStatus::Cancelled->value => [],
            TripStatus::Aborted->value => [],
        ];
    }

    /**
     * The destructive set for this aggregate.
     *
     * `cancelled` and `aborted` are separated on purpose: cancelling pre-departure destroys planned
     * work, aborting post-departure destroys a trip that has already cost money. Both must say which.
     */
    /** @return list<string> */
    protected function transitionsRequiringReason(): array
    {
        return [
            TripStatus::Cancelled->value,
            TripStatus::Aborted->value,
            TripStatus::Reopened->value,
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return HasMany<Load, $this> */
    public function loads(): HasMany
    {
        return $this->hasMany(Load::class)->orderBy('stop_sequence')->orderBy('id');
    }

    /** @return HasMany<ProofOfDelivery, $this> */
    public function proofs(): HasMany
    {
        return $this->hasMany(ProofOfDelivery::class)->orderByDesc('created_at');
    }

    /** @return HasMany<TripAccessToken, $this> */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(TripAccessToken::class)->orderByDesc('created_at');
    }

    /** @return HasMany<TripCost, $this> */
    public function tripCosts(): HasMany
    {
        return $this->hasMany(TripCost::class)->orderBy('occurred_on')->orderBy('id');
    }

    /** @return HasMany<Chargeable, $this> */
    public function chargeables(): HasMany
    {
        return $this->hasMany(Chargeable::class)->orderBy('id');
    }

    /**
     * Tonnes on board, summed from the loads rather than stored.
     *
     * The gate and the loading schedule both read this, and a stored total would be a second truth
     * about a number the weighbridge checks. It is **not** memoised on the model: the loading schedule
     * adds a load and then asks the same instance what the trip weighs, and a cached sum would answer
     * with the manifest as it was before the row existed. One indexed `SUM` over a handful of loads per
     * trip is cheaper than that bug; The denormalised rollups are for money across many cost types,
     * which is a different problem.
     */
    public function tonnes(): float
    {
        return round((float) $this->loads()->sum('tonnes_10_2'), 2);
    }

    /** The trip's own tonnage figure, printed the way the board prints it. */
    public function tonnageLabel(): string
    {
        $tonnes = $this->tonnes();

        return rtrim(rtrim(number_format($tonnes, 2, '.', ''), '0'), '.').' t';
    }

    /**
     * The odometer the trip actually covered, or null until the closing reading exists.
     *
     * Computed rather than stored because lets a *suspect* reading be recorded against the trip
     * without moving the truck; a stored distance would have to pick a side of that rule and be wrong
     * about the other one.
     */
    public function distanceCoveredKm(): ?int
    {
        if ($this->odometer_start_km === null || $this->odometer_end_km === null) {
            return null;
        }

        return $this->odometer_end_km - $this->odometer_start_km;
    }

    /** The guard for the kiosk surface: facts may be captured, completion may not be decided. */
    public function mayBeCompletedByKiosk(): bool
    {
        return ! $this->status->requiresOfficeRole();
    }

    protected static function booted(): void
    {
        self::creating(function (self $trip): void {
            $trip->id ??= (string) Str::ulid();

            // The `[*] → draft` is the creation itself. Named here for the same reason as
            // `Job`: the transition table reads the model's current state, and a draft that has to be
            // re-read from the database before it can move is a draft that cannot move.
            $trip->status ??= TripStatus::Draft;
        });
    }
}
