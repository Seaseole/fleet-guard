<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit\Models;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\AuditTrailIsAppendOnly;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OPERATIONAL_EVENT — the state-machine trail: what moved, from what to what, who moved it,
 * and why.
 *
 * Append-only for the same reason as the security trail, with the sharper edge: this table is the
 * evidence a period-close or a dispute reads back. "Why is this work order closed" has to be
 * answerable from rows that were written when it happened, which is exactly what an editable row
 * cannot be.
 *
 * @property int $id
 * @property int $company_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string|null $from_state
 * @property string $to_state
 * @property int|null $actor_id
 * @property string $actor_kind
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
#[Fillable(['aggregate_type', 'aggregate_id', 'from_state', 'to_state', 'actor_id', 'actor_kind', 'reason', 'context', 'occurred_at'])]
final class OperationalEvent extends Model
{
    use BelongsToCompany;

    /** A row with no `updated_at`: an event is a fact, and a fact does not have two versions. */
    public const UPDATED_AT = null;

    protected $table = 'operational_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $event): void {
            throw AuditTrailIsAppendOnly::writeAttempted($event, 'update');
        });

        self::deleting(function (self $event): void {
            throw AuditTrailIsAppendOnly::writeAttempted($event, 'delete');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorKind(): ActorKind
    {
        return ActorKind::from($this->actor_kind);
    }
}
