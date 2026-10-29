<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Policies\TruckPolicy;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 TRUCK — the tractor unit, its papers' home and the odometer everything else is measured
 * against.
 *
 * Money is integer minor units in the company base currency (`purchase_amount_base`); physical
 * quantities (capacity, engine hours, consumption) are decimals because they are not money. This is
 * the same split D6 draws, and the reason `capacity_tonnes_10_2` does not carry a currency.
 *
 * `status` moves through §10.1's table; the sale and the out-of-service decision require a reason.
 * Selling also clears `active` in the Action, so "sold" never appears in a dispatch picker just
 * because someone forgot a second checkbox.
 *
 * @property int $id
 * @property int $company_id
 * @property string $registration_no
 * @property string|null $fleet_no
 * @property string|null $vin
 * @property string|null $make
 * @property string|null $model
 * @property int|null $year
 * @property FleetStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property string|float|null $capacity_tonnes_10_2
 * @property int $odometer_km
 * @property string|float|null $engine_hours_10_2
 * @property int $purchase_amount_base
 * @property Carbon|null $purchased_on
 * @property string|null $tyre_size
 * @property int|null $service_interval_km
 * @property int|null $service_interval_hours
 * @property int|null $service_interval_months
 * @property string $fuel_type
 * @property int|null $main_tank_litres
 * @property int|null $aux_tank_litres
 * @property string|float|null $expected_consumption_per_100
 * @property array<string, mixed>|null $compliance_flags
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(TruckPolicy::class)]
final class Truck extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** @var list<string> */
    protected $fillable = [
        'registration_no',
        'fleet_no',
        'vin',
        'make',
        'model',
        'year',
        'capacity_tonnes_10_2',
        'odometer_km',
        'engine_hours_10_2',
        'purchase_amount_base',
        'purchased_on',
        'tyre_size',
        'service_interval_km',
        'service_interval_hours',
        'service_interval_months',
        'fuel_type',
        'main_tank_litres',
        'aux_tank_litres',
        'expected_consumption_per_100',
        'compliance_flags',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'year' => 'integer',
            'status' => FleetStatus::class,
            'state_changed_at' => 'datetime',
            'capacity_tonnes_10_2' => 'float',
            'odometer_km' => 'integer',
            'engine_hours_10_2' => 'float',
            'purchase_amount_base' => 'integer',
            'purchased_on' => 'date',
            'service_interval_km' => 'integer',
            'service_interval_hours' => 'integer',
            'service_interval_months' => 'integer',
            'main_tank_litres' => 'integer',
            'aux_tank_litres' => 'integer',
            'expected_consumption_per_100' => 'float',
            'compliance_flags' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * §10.1's table. `sold` is terminal; a reconditioned truck is a new decision against the same
     * row only through an explicit process this phase does not offer, not by editing the status.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            FleetStatus::Available->value => [FleetStatus::OnTrip->value, FleetStatus::InWorkshop->value, FleetStatus::OutOfService->value, FleetStatus::Sold->value],
            FleetStatus::OnTrip->value => [FleetStatus::Available->value, FleetStatus::InWorkshop->value, FleetStatus::OutOfService->value],
            FleetStatus::InWorkshop->value => [FleetStatus::Available->value, FleetStatus::OutOfService->value, FleetStatus::Sold->value],
            FleetStatus::OutOfService->value => [FleetStatus::Available->value, FleetStatus::InWorkshop->value, FleetStatus::Sold->value],
            FleetStatus::Sold->value => [],
        ];
    }

    /** @return list<string> */
    protected function transitionsRequiringReason(): array
    {
        return [FleetStatus::OutOfService->value, FleetStatus::Sold->value];
    }

    /** @return HasMany<VehicleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class)->orderByDesc('from_at');
    }

    /** @return HasMany<OdometerReading, $this> */
    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class)->orderByDesc('read_on')->orderByDesc('id');
    }

    /** "T-07 · BWD1234K" — how the register names a truck; fleet number first when there is one. */
    public function label(): string
    {
        return $this->fleet_no !== null && $this->fleet_no !== ''
            ? $this->fleet_no.' · '.$this->registration_no
            : $this->registration_no;
    }
}
