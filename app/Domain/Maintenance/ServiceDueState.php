<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The derived schedule state: `ok | due_soon | overdue | blocked`.
 *
 * Derived, never stored — see the migration's note and the argument for document validity. The
 * state is what the register sorts on and what the nightly source turns into a `service_due` alert;
 * nothing writes it back to `service_schedules`.
 *
 * `blocked` is deliberately not "overdue and ignored". It means the schedule cannot be acted on at
 * all because the truck is out of service or sold — chasing a workshop slot for a truck that left
 * the fleet is noise, and the register is only worth reading if it is not noise.
 */
enum ServiceDueState: string
{
    case Ok = 'ok';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::DueSoon => 'Due soon',
            self::Overdue => 'Overdue',
            self::Blocked => 'Blocked',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::DueSoon => 'warning',
            self::Overdue => 'danger',
            self::Blocked => 'neutral',
        };
    }

    /** Whether the state is something a person has to deal with (the alert source's question). */
    public function needsAttention(): bool
    {
        return $this === self::DueSoon || $this === self::Overdue;
    }
}
