<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Policies\EquipmentPolicy;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * EQUIPMENT — loaders, generators and toolboxes.
 *
 * Never `on_trip`: nothing is dispatched on a generator, and its transition table omits the state
 * the DB CHECK also omits for this table.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $kind
 * @property FleetStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property int $purchase_amount_base
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(EquipmentPolicy::class)]
final class Equipment extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'kind',
        'purchase_amount_base',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => FleetStatus::class,
            'state_changed_at' => 'datetime',
            'purchase_amount_base' => 'integer',
        ];
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            FleetStatus::Available->value => [FleetStatus::InWorkshop->value, FleetStatus::OutOfService->value, FleetStatus::Sold->value],
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
}
