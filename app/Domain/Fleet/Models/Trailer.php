<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Policies\TrailerPolicy;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 TRAILER — assigned beside a truck, not owned by one.
 *
 * The same state words as a truck because the dispatcher asks the same question of both, and the
 * same reason rule on disposal. `odometer_km` is kept because some trailers carry hubodometers;
 * it stays 0 when they do not.
 *
 * @property int $id
 * @property int $company_id
 * @property string $registration_no
 * @property string|null $fleet_no
 * @property string|null $vin
 * @property string $type
 * @property string|float|null $capacity_tonnes_10_2
 * @property string|null $axle_config
 * @property FleetStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property int $odometer_km
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(TrailerPolicy::class)]
final class Trailer extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** @var list<string> */
    protected $fillable = [
        'registration_no',
        'fleet_no',
        'vin',
        'type',
        'capacity_tonnes_10_2',
        'axle_config',
        'odometer_km',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'capacity_tonnes_10_2' => 'float',
            'status' => FleetStatus::class,
            'state_changed_at' => 'datetime',
            'odometer_km' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return array<string, list<string>> */
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

    public function label(): string
    {
        return $this->fleet_no !== null && $this->fleet_no !== ''
            ? $this->fleet_no.' · '.$this->registration_no
            : $this->registration_no;
    }
}
