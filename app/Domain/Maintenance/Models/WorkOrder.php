<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\StockMovementKind;
use App\Domain\Maintenance\WorkOrderKind;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 WORK_ORDER — §10.6's machine, and the totals its lines add up to.
 *
 * The transition table is here rather than in an Action for §10.1's reason: the CHECK in the
 * migration and this array are the only two places the vocabulary exists, and they sit next to the
 * enum they came from. The Actions decide *who* may move it and *what else* must be true; this decides
 * whether the move exists.
 *
 * `recalculateCosts()` is the only writer of the four cost columns. It refuses once the vehicle has
 * been released (`completed`), because from that point the row is what the workshop spent, and a
 * later edit would silently re-open a month the accountant has already read.
 *
 * Money is exposed as {@see Money} so a screen cannot print a foreign amount with the Pula symbol
 * (§5.1); the row stores base minor units, which is what the close-time capitalise decision and the
 * Phase 5 bills will both read.
 *
 * @property string $id
 * @property int $company_id
 * @property string $wo_no
 * @property int $truck_id
 * @property int|null $trailer_id
 * @property string|null $trip_id
 * @property int|null $service_schedule_id
 * @property WorkOrderKind $kind
 * @property WorkOrderStatus $status
 * @property string $title
 * @property string|null $description
 * @property string|float|null $estimated_hours_10_2
 * @property string $base_currency_code
 * @property int $labour_cost_base
 * @property int $parts_cost_base
 * @property int $external_cost_base
 * @property int $total_cost_base
 * @property int $approval_required_amount_base
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $mechanic_employee_id
 * @property int|null $supplier_id
 * @property bool $capitalised
 * @property int $capitalised_amount_base
 * @property HoldReason|null $hold_reason
 * @property string|null $hold_note
 * @property Carbon $opened_on
 * @property Carbon|null $planned_for
 * @property int|null $completion_odometer_km
 * @property Carbon|null $closed_at
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property int|null $raised_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WorkOrder extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** ULID key (§2): the work order names itself on documents and later on ledger dimensions. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'wo_no',
        'truck_id',
        'trailer_id',
        'trip_id',
        'service_schedule_id',
        'kind',
        'status',
        'title',
        'description',
        'estimated_hours_10_2',
        'base_currency_code',
        'labour_cost_base',
        'parts_cost_base',
        'external_cost_base',
        'total_cost_base',
        'approval_required_amount_base',
        'approved_by',
        'approved_at',
        'mechanic_employee_id',
        'supplier_id',
        'capitalised',
        'capitalised_amount_base',
        'hold_reason',
        'hold_note',
        'opened_on',
        'planned_for',
        'completion_odometer_km',
        'closed_at',
        'previous_state',
        'state_changed_at',
        'raised_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'trailer_id' => 'integer',
            'service_schedule_id' => 'integer',
            'kind' => WorkOrderKind::class,
            'status' => WorkOrderStatus::class,
            'estimated_hours_10_2' => 'float',
            'labour_cost_base' => 'integer',
            'parts_cost_base' => 'integer',
            'external_cost_base' => 'integer',
            'total_cost_base' => 'integer',
            'approval_required_amount_base' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'mechanic_employee_id' => 'integer',
            'supplier_id' => 'integer',
            'capitalised' => 'boolean',
            'capitalised_amount_base' => 'integer',
            'hold_reason' => HoldReason::class,
            'opened_on' => 'date',
            'planned_for' => 'date',
            'completion_odometer_km' => 'integer',
            'closed_at' => 'datetime',
            'state_changed_at' => 'datetime',
            'raised_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $workOrder): void {
            $workOrder->id ??= (string) Str::ulid();
        });
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            WorkOrderStatus::Draft->value => [
                WorkOrderStatus::AwaitingApproval->value,
                WorkOrderStatus::Approved->value,
                WorkOrderStatus::Cancelled->value,
            ],
            WorkOrderStatus::AwaitingApproval->value => [
                WorkOrderStatus::Approved->value,
                WorkOrderStatus::Rejected->value,
            ],
            WorkOrderStatus::Approved->value => [
                WorkOrderStatus::Planned->value,
                WorkOrderStatus::InProgress->value,
                // The estimate can grow before work starts: a line added at 3,000 takes a 4,000 order
                // past a 5,000 limit only in company with the others, and the approval must be asked
                // again rather than inherited from a total nobody saw (§10.6).
                WorkOrderStatus::AwaitingApproval->value,
                WorkOrderStatus::Cancelled->value,
            ],
            WorkOrderStatus::Planned->value => [
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::AwaitingApproval->value,
                WorkOrderStatus::Cancelled->value,
            ],
            WorkOrderStatus::InProgress->value => [
                WorkOrderStatus::OnHold->value,
                WorkOrderStatus::Completed->value,
            ],
            WorkOrderStatus::OnHold->value => [WorkOrderStatus::InProgress->value],
            WorkOrderStatus::Completed->value => [WorkOrderStatus::Inspected->value],
            WorkOrderStatus::Inspected->value => [WorkOrderStatus::Closed->value],
            WorkOrderStatus::Closed->value => [],
            WorkOrderStatus::Rejected->value => [],
            WorkOrderStatus::Cancelled->value => [],
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

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<ServiceSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ServiceSchedule::class, 'service_schedule_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mechanic_employee_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<WorkOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class)->orderBy('id');
    }

    /** @return Collection<int, WorkOrderLine> */
    public function costLines(): Collection
    {
        return $this->lines()->get();
    }

    /**
     * Recompute the four frozen totals from the estimate lines **and the parts actually issued**.
     * The only writer of those columns.
     *
     * `$requireEditable` is §10.6's two-sided rule. Adding or removing an estimate line is refused
     * once the vehicle is in the workshop, because the estimate stops being an estimate; but a part
     * issued *while* the job runs is a fact, and the totals must follow it. The issuing action passes
     * `false`; the line Actions leave the default.
     *
     * Issued parts are summed here rather than written as lines: a stock issue is what the shelf gave
     * up, not what somebody thought the job would cost, and the two are answered by different tables.
     */
    public function recalculateCosts(bool $requireEditable = true): self
    {
        if ($requireEditable && ! $this->status->acceptsLines()) {
            throw new InvalidArgumentException(sprintf(
                '%s is past the point where its costs are facts; a work order’s lines are fixed once the vehicle is released (§10.6).',
                $this->wo_no,
            ));
        }

        $labour = 0;
        $parts = 0;
        $external = 0;

        foreach ($this->costLines() as $line) {
            match ($line->kind->totalBucket()) {
                'labour' => $labour += $line->base_amount,
                'parts' => $parts += $line->base_amount,
                default => $external += $line->base_amount,
            };
        }

        $parts += $this->issuedPartsValueBase();

        $this->forceFill([
            'labour_cost_base' => $labour,
            'parts_cost_base' => $parts,
            'external_cost_base' => $external,
            'total_cost_base' => $labour + $parts + $external,
        ])->save();

        return $this;
    }

    /**
     * What stock issues have put into this order, in base minor units — the missing half of
     * `parts_cost_base`. Absolute values, because an issue's delta is negative.
     */
    public function issuedPartsValueBase(): int
    {
        return (int) StockMovement::query()
            ->where('work_order_id', $this->id)
            ->where('kind', StockMovementKind::Issue->value)
            ->selectRaw('coalesce(sum(abs(value_delta_base)), 0) as issued')
            ->value('issued');
    }

    /** @return HasMany<StockMovement, $this> */
    public function issuedParts(): HasMany
    {
        return $this->hasMany(StockMovement::class)
            ->where('kind', StockMovementKind::Issue->value)
            ->orderBy('moved_on')
            ->orderBy('id');
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_cost_base, $this->base_currency_code);
    }

    public function labourTotal(): Money
    {
        return Money::ofMinor($this->labour_cost_base, $this->base_currency_code);
    }

    public function partsTotal(): Money
    {
        return Money::ofMinor($this->parts_cost_base, $this->base_currency_code);
    }

    public function externalTotal(): Money
    {
        return Money::ofMinor($this->external_cost_base, $this->base_currency_code);
    }

    public function capitalisedAmount(): Money
    {
        return Money::ofMinor($this->capitalised_amount_base, $this->base_currency_code);
    }

    /** Whether this order's spend needed an owner's approval when it was raised (§10.6). */
    public function neededApproval(): bool
    {
        return $this->approval_required_amount_base > 0;
    }

    public function isFrozen(): bool
    {
        return $this->status === WorkOrderStatus::Closed;
    }

    /** "WO-00007 · BWD1234K" — the register's name for a work order. */
    public function label(): string
    {
        return $this->wo_no.' · '.($this->truck?->label() ?? 'Truck #'.$this->truck_id);
    }
}
