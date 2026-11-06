<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit\Models;

use App\Domain\Kernel\Audit\AuditTrailIsAppendOnly;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SECURITY_EVENT — the trail that answers "who touched this, when, from where".
 *
 * Append-only in code (layer 1). It has no DB trigger counterpart because the failure this guards
 * against is an application path, not a hand in a SQL client, and a trigger on a table nothing
 * updates by design would be forty lines of SQL protecting a promise already kept.
 *
 * The document kinds (the per-download audit) live here rather than in `audits` because an
 * access trail answers a different question from a before/after diff: "who read this licence" is a
 * list of reads with zero writes, and laravel-auditing has nothing to record for it.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 * @property Carbon $happened_at
 */
final class SecurityEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'security_events';

    /**
     * `happened_at` is the only time on this row, and it is the event's own moment rather
     * than the insert's: a queued or replayed trail entry must carry when it occurred, not when a
     * worker got to it. So Eloquent's timestamps are off rather than duplicated.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'kind', 'ip_address', 'user_agent', 'meta', 'happened_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // `updating`/`deleting` and not `saving`: a fresh insert is the only legitimate write, and
        // `saving` fires on create too — which would refuse the recorder's own row.
        self::updating(function (self $event): void {
            throw AuditTrailIsAppendOnly::writeAttempted($event, 'update');
        });

        self::deleting(function (self $event): void {
            throw AuditTrailIsAppendOnly::writeAttempted($event, 'delete');
        });
    }
}
