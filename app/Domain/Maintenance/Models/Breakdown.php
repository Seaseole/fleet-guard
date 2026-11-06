<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\BreakdownCause;
use App\Domain\Operations\Models\Trip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * BREAKDOWN — a truck stopped at the roadside, and what came of it.
 *
 * The row exists before the repair does: `work_order_id` is null until someone raises a job from it,
 * because a breakdown's first job is to record that the truck is down and where, not to wait for a
 * purchase order. Hours lost are kept beside the description because they are the number the
 * availability conversation is actually about.
 *
 * Damage recovered from a driver is a claim, not a ledger entry — the bool records the decision, and
 * Phase 6's payroll deduction is where it becomes money. Only a `driver` cause can be marked, which the
 * table's CHECK states as well.
 *
 * @property string $id
 * @property int $company_id
 * @property int $truck_id
 * @property string|null $trip_id
 * @property Carbon $occurred_at
 * @property string $location
 * @property string $description
 * @property int $hours_lost
 * @property string|null $work_order_id
 * @property BreakdownCause $cause
 * @property bool $recoverable_from_driver
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Breakdown extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'truck_id',
        'trip_id',
        'occurred_at',
        'location',
        'description',
        'hours_lost',
        'work_order_id',
        'cause',
        'recoverable_from_driver',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'occurred_at' => 'datetime',
            'hours_lost' => 'integer',
            'cause' => BreakdownCause::class,
            'recoverable_from_driver' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $breakdown): void {
            $breakdown->id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function hasRepair(): bool
    {
        return $this->work_order_id !== null;
    }

    /** "BWD1234K · 30 Oct 2026 · Mechanical failure" — the register's name for a breakdown. */
    public function label(): string
    {
        return sprintf(
            '%s · %s · %s',
            $this->truck?->label() ?? 'Truck #'.$this->truck_id,
            $this->occurred_at?->format('d M Y') ?? '',
            $this->cause->label(),
        );
    }
}
