<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\BorderCrossingDirection;
use App\Domain\Operations\BorderCrossingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.1 BORDER_CROSSING — one gate event on one trip, carrying §10.9's machine.
 *
 * The post's country is **copied** onto the row the way a trip copies its route: a later correction
 * to the master record must not silently restate where a truck went, and Phase 6's per-country
 * summary reads the crossing, not the register.
 *
 * `direction` is stored because the same gate appears twice on a round trip; the unique key on
 * `(trip, post, direction)` is what makes a retried plan return the existing row instead of counting
 * the gate twice.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_id
 * @property int $border_post_id
 * @property string $country_code
 * @property BorderCrossingDirection $direction
 * @property BorderCrossingStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property Carbon|null $entered_at
 * @property Carbon|null $exited_at
 * @property string|null $transit_doc_no
 * @property string|null $weighbridge_ticket_id
 * @property string|null $clearance_state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BorderCrossing extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /** ULID key (§2), written by the `creating` hook below. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'border_post_id',
        'country_code',
        'direction',
        'status',
        'entered_at',
        'exited_at',
        'transit_doc_no',
        'weighbridge_ticket_id',
        'clearance_state',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'border_post_id' => 'integer',
            'direction' => BorderCrossingDirection::class,
            'status' => BorderCrossingStatus::class,
            'state_changed_at' => 'datetime',
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $crossing): void {
            $crossing->id ??= (string) Str::ulid();
        });
    }

    /**
     * §10.9's reachable edges. `agent_reconciled` and `closed` are absent on purpose: Phase 6 owns the
     * advance that makes reconciliation possible, and an edge that led to a state nothing can verify
     * would be a door onto a cliff.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            BorderCrossingStatus::Planned->value => [BorderCrossingStatus::Entered->value],
            BorderCrossingStatus::Entered->value => [BorderCrossingStatus::Exited->value],
            BorderCrossingStatus::Exited->value => [BorderCrossingStatus::CostsCaptured->value],
            BorderCrossingStatus::CostsCaptured->value => [],
            BorderCrossingStatus::AgentReconciled->value => [],
            BorderCrossingStatus::Closed->value => [],
        ];
    }

    /** @return BelongsTo<BorderPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BorderPost::class, 'border_post_id');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return HasMany<BorderCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(BorderCost::class)->orderBy('occurred_on');
    }

    public function label(): string
    {
        return ($this->post?->name ?? $this->country_code).' · '.$this->direction->label();
    }
}
