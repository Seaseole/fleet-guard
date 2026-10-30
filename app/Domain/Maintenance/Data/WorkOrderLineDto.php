<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Maintenance\WorkOrderLineKind;
use InvalidArgumentException;

/**
 * One line as a screen hands it to an Action (D1).
 *
 * `quantityX100` and `unitCostMinor` are integers at §5.5's scale; the DTO's job is to refuse a line
 * that cannot be priced (no description, a zero quantity, a free part) and to produce the base amount
 * by the one rounding the schema restates — `quantity × unit ÷ 100`, HALF_UP, once.
 *
 * Currency is carried rather than assumed so the Action can refuse a foreign line **by name**: the
 * §5.2 rate block lives on `trip_costs`, and a workshop line entered in ZAR arrives with the supplier
 * bill in Phase 5, not with an invented rate here.
 */
final readonly class WorkOrderLineDto
{
    public function __construct(
        public WorkOrderLineKind $kind,
        public string $description,
        public int $quantityX100,
        public int $unitCostMinor,
        public string $currencyCode,
        public ?int $partId = null,
        public bool $capitalCandidate = false,
    ) {
        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException('A work-order line needs a description — "misc" six weeks later tells nobody what was done.');
        }

        if (mb_strlen($description) > 160) {
            throw new InvalidArgumentException('Keep a line description to 160 characters.');
        }

        if ($quantityX100 <= 0) {
            throw new InvalidArgumentException('A line quantity must be above zero.');
        }

        if ($unitCostMinor <= 0) {
            throw new InvalidArgumentException('A line unit cost must be above zero — a free part is a warranty recovery, not a line (§10.6).');
        }

        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new InvalidArgumentException('A line needs a three-letter currency code.');
        }

        if ($partId !== null && ! $kind->mayNamePart()) {
            throw new InvalidArgumentException('Only a part line may name a catalogue part.');
        }
    }

    /** Quantity × unit cost, rounded HALF_UP once — the same figure the schema's CHECK pins. */
    public function baseAmount(): int
    {
        return intdiv($this->quantityX100 * $this->unitCostMinor + 50, 100);
    }
}
