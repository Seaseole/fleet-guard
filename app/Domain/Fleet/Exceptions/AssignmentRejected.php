<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Exceptions;

use RuntimeException;

/**
 * An assignment the fleet refuses: a vehicle that is not available, a driver whose papers do not
 * cover the start date, or a truck/driver/trailer already committed over the same interval.
 *
 * The message is shown as-is on the assignment form, so each factory writes a sentence naming the
 * asset by its registration or the driver by name — "Unavailable" alone leaves the dispatcher to
 * guess which of four inputs was wrong.
 */
final class AssignmentRejected extends RuntimeException
{
    public static function vehicleNotAvailable(string $kind, string $label, string $status): self
    {
        return new self("{$kind} {$label} is {$status}, so it cannot be assigned. Only an available asset can start an assignment.");
    }

    public static function alreadyAssigned(string $kind, string $label, string $from, ?string $to): self
    {
        $until = $to ?? 'still open';

        return new self("{$kind} {$label} is already assigned from {$from} ({$until}). Release that assignment first — history is never overwritten.");
    }

    public static function driverMissing(int $driverId): self
    {
        return new self("No driver #{$driverId} exists in this company's records, so the assignment cannot name one.");
    }

    public static function driverNotEligible(string $name, string $on): self
    {
        return new self("{$name} is not eligible to drive on {$on}: their status, licence or PDP permit does not cover that date. Renew the paper or pick another driver.");
    }

    public static function driverAlreadyAssigned(string $name, string $from, ?string $to): self
    {
        $until = $to ?? 'still open';

        return new self("{$name} is already assigned from {$from} ({$until}). One driver cannot hold two trucks over the same interval.");
    }

    public static function alreadyReleased(): self
    {
        return new self('This assignment is already closed. Releasing it again would rewrite history.');
    }

    public static function releaseBeforeStart(): self
    {
        return new self('An assignment cannot end before it starts.');
    }
}
