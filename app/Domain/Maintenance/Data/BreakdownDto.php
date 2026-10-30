<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Maintenance\Actions\RecordBreakdown;
use App\Domain\Maintenance\BreakdownCause;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A roadside stop as the "record breakdown" dialog hands it to {@see RecordBreakdown} (D1).
 *
 * Where it happened and what was wrong are both required: a register that says "broke down" with no
 * place cannot answer the corridor question ("this hill again"), and a breakdown with no description
 * cannot become a work order. Hours lost are optional and default to zero — many stops are repaired
 * at the roadside without anybody logging an hour.
 *
 * `recoverableFromDriver` is a decision, not a guess, and only a `driver` cause may carry it (§4.3's
 * CHECK). The DTO refuses the other causes by name so the refusal is a sentence, not a SQL error.
 */
final readonly class BreakdownDto
{
    public function __construct(
        public int $truckId,
        public CarbonInterface $occurredAt,
        public string $location,
        public string $description,
        public BreakdownCause $cause,
        public int $hoursLost = 0,
        public ?string $tripId = null,
        public bool $recoverableFromDriver = false,
    ) {
        $location = trim($location);

        if ($location === '') {
            throw new InvalidArgumentException('A breakdown needs a location — "somewhere on the A1" is not a place the workshop can drive to.');
        }

        if (mb_strlen($location) > 120) {
            throw new InvalidArgumentException('Keep the location to 120 characters.');
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('A breakdown needs a description of what happened.');
        }

        if (mb_strlen(trim($description)) > 500) {
            throw new InvalidArgumentException('Keep the description to 500 characters; the repair detail belongs on the work order.');
        }

        if ($hoursLost < 0) {
            throw new InvalidArgumentException('Hours lost cannot be negative.');
        }

        if ($hoursLost > 10000) {
            throw new InvalidArgumentException('Hours lost that large is a write-off, not a breakdown; correct the figure.');
        }

        if ($recoverableFromDriver && ! $cause->allowsDriverRecovery()) {
            throw new InvalidArgumentException(sprintf(
                'Only a driver-caused stop can be recovered from the driver; this one is recorded as %s.',
                strtolower($cause->label()),
            ));
        }
    }

    public function place(): string
    {
        return trim($this->location);
    }

    public function story(): string
    {
        return trim($this->description);
    }
}
