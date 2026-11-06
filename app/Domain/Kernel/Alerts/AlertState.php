<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts;

use App\Domain\Kernel\Documents\Lifecycle;

/**
 * The alert machine: `open → acknowledged → resolved`, plus `muted` with an expiry.
 *
 * The transition table is a method rather than a column because the rule is that an illegal move
 * is refused in code and the legal one is recorded as an event. `Kernel/State` still holds no classes
 * — drew it, and slice 7 found nothing in it — so this enum carries its own table exactly the way
 * {@see Lifecycle} does, rather than reaching for an abstraction that
 * does not exist yet.
 *
 * `muted → open` is in the table even though no screen offers it: the sweep that finds a mute whose
 * `muted_until` has passed reopens the alert, and that is the "so muting cannot silently become
 * permanent" turned into a mechanism. Without the transition in the table, the honest thing to do at
 * 02:00 would be to refuse and leave a permanently muted alert on the register.
 */
enum AlertState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Muted = 'muted';
    case Resolved = 'resolved';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Muted, self::Resolved],

            // Someone has looked at it. From here it can still be muted (the thing they are waiting
            // on has an expected date) or resolved. It does not go back to open: an un-acknowledge is
            // not a decision anyone needs to make, and the register reads by state, not by history.
            self::Acknowledged => [self::Muted, self::Resolved],

            // The mute ending is the sweep's, not a clerk's; the two human exits are here too.
            self::Muted => [self::Open, self::Acknowledged, self::Resolved],

            // Final. A recurrence is a new alert, not a reopened one — see RaiseAlert's episode key,
            // which is what lets a fixed-and-refailing check raise again without rewriting history.
            self::Resolved => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Whether moving *into* this state has to say why.
     *
     * Acknowledging says "I have seen this", which is not a claim about the world. Muting and
     * resolving both say the alert can be ignored or is finished, and the rule — a nullable
     * reason is how you lose the plot six months later — applies to both. The DB CHECKs
     * `alerts_muted_explained` and `alerts_resolved_explained` enforce the same pair.
     */
    public function requiresReason(): bool
    {
        return $this === self::Muted || $this === self::Resolved;
    }

    /**
     * Whether this state still counts as "needs attention".
     *
     * The cooldown test uses this, not `=== Open`: an acknowledged alert is still unresolved, and a
     * reminder that fires a second alert because the first one was looked at would punish the one
     * behaviour the register exists to encourage.
     */
    public function isLive(): bool
    {
        return $this !== self::Resolved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Muted => 'Muted',
            self::Resolved => 'Resolved',
        };
    }

    /** The `ui/badge` tone for this state; the caller still supplies the icon. */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Muted => 'neutral',
            self::Resolved => 'success',
        };
    }
}
