<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Operations\Support\JobProgress;

/**
 * The commercial lifecycle of a job (§10.3).
 *
 * Only states a person can set appear here. §10.3 names four more — `all_trips_completed`,
 * `ready_to_invoice`, `invoiced`, `settled` — and calls them derived aggregates: they are answers
 * about the job's trips and invoices, not decisions, so they are computed by
 * {@see JobProgress} and rendered as badges. A storable enum with a
 * `settled` case would eventually be set by hand, and then the ledger and the jobs board disagree.
 *
 * `declined` and `cancelled` are reachable only before acceptance, which is why no state after
 * `accepted` lists them: an accepted job that will not run is `suspended` or left open, not erased.
 */
enum JobStatus: string
{
    case Enquiry = 'enquiry';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case InExecution = 'in_execution';
    case Suspended = 'suspended';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => 'Enquiry',
            self::Quoted => 'Quoted',
            self::Accepted => 'Accepted',
            self::InExecution => 'In execution',
            self::Suspended => 'Suspended',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }

    /** The `ui/badge` tone for the jobs board (DESIGN-SYSTEM.md §1.5). */
    public function tone(): string
    {
        return match ($this) {
            self::Enquiry => 'neutral',
            self::Quoted => 'accent',
            self::Accepted => 'success',
            self::InExecution => 'accent',
            self::Suspended => 'warning',
            self::Declined => 'neutral',
            self::Cancelled => 'danger',
            self::Closed => 'neutral',
        };
    }

    /** Trips exist only against work the customer has agreed to (§4.1: job → trips). */
    public function mayRunTrips(): bool
    {
        return $this === self::Accepted || $this === self::InExecution;
    }

    /** §10.3's "any pre-completion state" — the states a suspension may be lifted from. */
    public function isPreCompletion(): bool
    {
        return in_array($this, [self::Accepted, self::InExecution, self::Suspended], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Declined, self::Cancelled, self::Closed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
