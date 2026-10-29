<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit;

/**
 * §9.2's principals. Four, because there are exactly four things that can move a record: a
 * authenticated person, a trip-scoped kiosk token, the scheduler, and the system itself reacting
 * to a request (§10.1's "every state change has an actor").
 *
 * The distinction is what makes §9.6's shared driver phone survivable: "the driver's phone
 * captured this POD" and "the dispatcher changed this trip" are different rows with different
 * actor kinds, and collapsing them into `users.id` would require driver accounts the design
 * explicitly refuses.
 */
enum ActorKind: string
{
    case User = 'user';
    case Kiosk = 'kiosk';
    case System = 'system';
    case Scheduler = 'scheduler';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Person',
            self::Kiosk => 'Kiosk token',
            self::System => 'System',
            self::Scheduler => 'Scheduler',
        };
    }
}
