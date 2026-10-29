<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * The operational lifecycle of a trip (§10.2) — sixteen states, one table.
 *
 * The enum is the vocabulary only; the machine lives in `Trip::transitions()` so it cannot drift from
 * the `trips_status_allowed` CHECK (§10.1). What belongs here is the *meaning* of a state for the
 * three rules §10.2 states in prose:
 *
 * - **pre-departure vs post-departure** decides whether a stopped trip is `cancelled` or `aborted`,
 *   and an aborted trip still carries costs and may still be billed — a cancelled one never does;
 * - **the kiosk's reach**: a driver captures facts and never decides completion, so `delivered`,
 *   `completed` and `closed` are office-only destinations ({@see self::requiresOfficeRole()});
 * - **what is still editable**: costs freeze at `closed` and thaw only through a re-open, which is why
 *   `reopened` returns to `completed` rather than to a pre-completion state.
 */
enum TripStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Assigned = 'assigned';
    case Loaded = 'loaded';
    case Departed = 'departed';
    case InTransit = 'in_transit';
    case AtBorder = 'at_border';
    case Arrived = 'arrived';
    case PodPending = 'pod_pending';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Planned => 'Planned',
            self::Assigned => 'Assigned',
            self::Loaded => 'Loaded',
            self::Departed => 'Departed',
            self::InTransit => 'In transit',
            self::AtBorder => 'At border',
            self::Arrived => 'Arrived',
            self::PodPending => 'POD pending',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
            self::Aborted => 'Aborted',
        };
    }

    /** The `ui/badge` tone for the dispatch board and trip detail (DESIGN-SYSTEM.md §1.5). */
    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::OnHold => 'neutral',
            self::Planned => 'neutral',
            self::Assigned, self::Loaded => 'accent',
            self::Departed, self::InTransit, self::AtBorder, self::Arrived => 'accent',
            self::PodPending => 'warning',
            self::Delivered => 'success',
            self::Completed => 'success',
            self::Closed => 'success',
            self::Reopened => 'warning',
            self::Cancelled => 'neutral',
            self::Aborted => 'danger',
        };
    }

    /** Before the truck has left, a stopped trip is a cancellation, not an abort (§10.2). */
    public function isPreDeparture(): bool
    {
        return in_array($this, [self::Draft, self::Planned, self::Assigned, self::Loaded, self::OnHold], true);
    }

    /** §10.2's "a driver may never transition to" list, at the level the kiosk guard asks about. */
    public function requiresOfficeRole(): bool
    {
        return in_array($this, [self::Delivered, self::Completed, self::Closed], true);
    }

    /** Costs are editable until the trip closes; a re-open keeps them editable on purpose (§10.2). */
    public function acceptsCostEdits(): bool
    {
        return ! in_array($this, [self::Closed, self::Cancelled], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Aborted], true);
    }

    /** The states that occupy a truck and driver on the dispatch board's day grid (§3.2). */
    public function holdsCapacity(): bool
    {
        return in_array($this, [self::Assigned, self::Loaded, self::Departed, self::InTransit, self::AtBorder, self::Arrived, self::PodPending], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
