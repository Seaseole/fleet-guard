<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exceptions;

use RuntimeException;

/**
 * The `planned → assigned` refusals, in the words a dispatcher can act on.
 *
 * The message is the deliverable: "not eligible" makes someone hunt for the reason, "licence lapsed
 * on 2026-10-27" makes them fix it. Every factory therefore names *which* paper, *which* row and
 * *what date* was tested.
 */
final class DispatchRefused extends RuntimeException
{
    /**
     * The gate's findings, as one refusal.
     *
     * Joined rather than truncated: the point of collecting every finding in one pass is that the
     * dispatcher sees all of them, so the message must carry the whole list — the first one only
     * would send them back to the board twice for the same trip.
     *
     * @param  list<string>  $findings
     */
    public static function papersOrFleetUnavailable(array $findings): self
    {
        return new self(
            'This trip cannot be dispatched yet: '.implode(' ', $findings)
        );
    }

    /**
     * Fleet refused the reservation (the assignment is the authority on "is this truck free").
     *
     * Re-wrapped rather than let `AssignmentRejected` escape, because the screen's error field and the
     * audit entry belong to the trip the operator was working on, not to a Fleet exception they have
     * not seen the name of.
     */
    public static function fleetRefusedReservation(string $reason): self
    {
        return new self('The vehicle could not be held for this trip: '.$reason);
    }

    public static function unitMissing(string $kind, int $id): self
    {
        return new self(sprintf('The %s #%d is not in this company\'s fleet, so the trip cannot be dispatched.', $kind, $id));
    }

    public static function unitNotAvailable(string $kind, string $label, string $status): self
    {
        return new self(sprintf('%s %s is %s, so it cannot be named on a dispatch (only an available unit).', ucfirst($kind), $label, $status));
    }

    public static function driverMissing(int $id): self
    {
        return new self(sprintf('Driver #%d does not exist in this company.', $id));
    }

    public static function driverNotEligible(string $name, string $on, ?string $licenceOn, ?string $permitOn, string $status): self
    {
        $papers = [
            'licence '.($licenceOn === null ? 'not on file' : 'expires '.$licenceOn),
            'PRDP permit '.($permitOn === null ? 'not on file' : 'expires '.$permitOn),
        ];

        return new self(sprintf(
            '%s cannot drive on %s: status is %s and %s (checks the papers against the trip\'s start date, not against today).',
            $name,
            $on,
            $status,
            implode(', ', $papers),
        ));
    }

    public static function paperExpired(string $documentType, string $subjectLabel, string $expiredOn): self
    {
        return new self(sprintf(
            'The filed %s for %s expired on %s. Renew the paper and file it before dispatching.',
            $documentType,
            $subjectLabel,
            $expiredOn,
        ));
    }

    public static function alreadyAborted(string $tripNo): self
    {
        return new self(sprintf('Trip %s has already been aborted; its costs stay where they are.', $tripNo));
    }
}
