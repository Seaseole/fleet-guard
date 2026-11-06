<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit;

use App\Domain\Kernel\Audit\Models\OperationalEvent;
use App\Domain\Kernel\Audit\Models\SecurityEvent;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The only two ways an event row gets written.
 *
 * One door rather than `SecurityEvent::create(...)` at each call site for the reason the design gives for
 * the numbering allocator: the fields that make a trail worth reading — actor, IP, company, moment —
 * are the ones a busy call site gets wrong or omits, and an event with a null actor answers nothing.
 * Here they are resolved once, the same way, every time.
 *
 * Synchronous by design (the design puts audit rows inside the business transaction): a download that is
 * queued and then fails leaves the file served with no record of it, which is the exact hole
 * closes.
 */
final class AuditRecorder
{
    /**
     * A security-trail row: who did something that a security review asks about.
     *
     * `meta` carries the target (document id, version id, session id) rather than a column per
     * target, because the kinds are closed and their subjects are not — a column set wide enough for
     * both is a table that half of its rows leave null.
     *
     * @param  array<string, mixed>  $meta
     */
    public function security(SecurityEventKind $kind, array $meta = [], ?int $userId = null): SecurityEvent
    {
        /** @var string|null $ip */
        $ip = request()->ip();

        /** @var string|null $agent */
        $agent = request()->userAgent();

        return SecurityEvent::create([
            'company_id' => CurrentCompany::id(),
            'user_id' => $userId ?? Auth::id(),
            'kind' => $kind->value,
            'ip_address' => is_string($ip) ? substr($ip, 0, 45) : null,
            'user_agent' => is_string($agent) ? substr($agent, 0, 512) : null,
            'meta' => $meta === [] ? null : $meta,
            'happened_at' => Carbon::now(),
        ]);
    }

    /**
     * A state transition on an aggregate. The `reason` is the argument the caller has to
     * think about: The design makes it mandatory for destructive transitions and the DB CHECK enforces
     * it, so passing one here is not a nicety — an event without it fails the insert.
     *
     * @param  array<string, mixed>  $context
     */
    public function operational(
        string $aggregateType,
        string $aggregateId,
        ?string $fromState,
        string $toState,
        ?string $reason = null,
        array $context = [],
        ActorKind $actorKind = ActorKind::User,
        ?int $actorId = null,
    ): OperationalEvent {
        return OperationalEvent::create([
            'company_id' => CurrentCompany::id(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_id' => $actorKind === ActorKind::User ? ($actorId ?? Auth::id()) : $actorId,
            'actor_kind' => $actorKind->value,
            'reason' => $reason === null ? null : substr($reason, 0, 255),
            'context' => $context === [] ? null : $context,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
