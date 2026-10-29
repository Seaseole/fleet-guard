<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Policies\VehicleAssignmentPolicy;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 VEHICLE_ASSIGNMENT — an append-only history of who had which truck and trailer.
 *
 * Ending an assignment writes `to_at`; nothing is deleted. `driver_id` is a plain foreign key and
 * deliberately **not** a `belongsTo(Driver::class)`: Fleet may not import People's model (D5), so
 * names come through {@see DriverDirectory} and this row only keeps the
 * id and the facts of the assignment.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property int|null $trailer_id
 * @property int|null $driver_id
 * @property string|null $trip_id
 * @property Carbon $from_at
 * @property Carbon|null $to_at
 * @property int|null $assigned_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(VehicleAssignmentPolicy::class)]
final class VehicleAssignment extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'trailer_id',
        'driver_id',
        'trip_id',
        'from_at',
        'to_at',
        'assigned_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'trailer_id' => 'integer',
            'driver_id' => 'integer',
            'from_at' => 'datetime',
            'to_at' => 'datetime',
            'assigned_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /** @return BelongsTo<Trailer, $this> */
    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Trailer::class);
    }

    public function isOpen(): bool
    {
        return $this->to_at === null;
    }
}
