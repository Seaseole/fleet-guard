<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A driver as another module may see one — D5's read model.
 *
 * Fleet needs to name a driver on an assignment and to know whether their papers are valid on the
 * trip's start date. It may not import `Driver`, so People answers with ids, words and dates. The
 * validity questions are methods here rather than fields so both modules cannot derive two
 * different answers from the same two dates.
 */
final readonly class DriverOption
{
    public function __construct(
        public int $id,
        public string $name,
        public string $employeeNo,
        public DriverStatus $status,
        public ?CarbonImmutable $licenceExpiresOn,
        public ?CarbonImmutable $permitExpiresOn,
        public bool $kioskEnabled,
    ) {}

    public function licenceValidOn(CarbonInterface $on): bool
    {
        return $this->licenceExpiresOn !== null
            && $this->licenceExpiresOn->greaterThanOrEqualTo(CarbonImmutable::instance($on)->startOfDay());
    }

    public function permitValidOn(CarbonInterface $on): bool
    {
        return $this->permitExpiresOn !== null
            && $this->permitExpiresOn->greaterThanOrEqualTo(CarbonImmutable::instance($on)->startOfDay());
    }

    /**
     * The dispatch question, in one place. A null expiry is false, not "valid forever" — the same
     * rule {@see Driver::eligibleOn()} applies on the People side.
     */
    public function eligibleOn(CarbonInterface $on): bool
    {
        return $this->status->mayBeDispatched()
            && $this->licenceValidOn($on)
            && $this->permitValidOn($on);
    }
}
