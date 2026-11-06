<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Maintenance\Actions\RaiseWorkOrder;
use App\Domain\Maintenance\WorkOrderKind;
use InvalidArgumentException;

/**
 * A work order as the "raise" dialog hands it to {@see RaiseWorkOrder}
 * (D1).
 *
 * At least one line is required. That is not tidiness: The threshold is computed from the
 * estimate, and an order with no estimate is one whose price was never considered — the control the
 * design calls "the single control that matters in a trucking workshop" would have nothing to compare.
 * A breakdown discovered on the road still gets a line ("diagnosis, 2 hours"), so the row says what
 * somebody thought it would cost.
 *
 * `tripId` is a ULID string rather than an int for the reason; a breakdown names the trip it stopped,
 * which is what makes a workshop cost attach to the run that needed it.
 */
final readonly class WorkOrderDto
{
    /**
     * @param  list<WorkOrderLineDto>  $lines
     */
    public function __construct(
        public int $truckId,
        public WorkOrderKind $kind,
        public string $title,
        public array $lines,
        public ?string $description = null,
        public ?float $estimatedHours = null,
        public ?int $trailerId = null,
        public ?string $tripId = null,
        public ?int $serviceScheduleId = null,
        public ?int $mechanicEmployeeId = null,
        public ?int $supplierId = null,
    ) {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('A work order needs a title — "brakes" is the minimum a mechanic can be handed.');
        }

        if (mb_strlen($title) > 140) {
            throw new InvalidArgumentException('Keep the work-order title to 140 characters; the detail goes in the description.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('A work order needs at least one cost line — the estimate is what the approval threshold is measured against.');
        }

        if ($estimatedHours !== null && $estimatedHours < 0) {
            throw new InvalidArgumentException('Estimated hours cannot be negative.');
        }

        if ($estimatedHours !== null && $estimatedHours > 9999.99) {
            throw new InvalidArgumentException('Estimated hours is a workshop estimate, not a number of that size.');
        }
    }

    /** The line amounts added up, in base minor units — the figure the threshold compares against. */
    public function estimatedTotalBase(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line->baseAmount();
        }

        return $total;
    }
}
