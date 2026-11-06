<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Kernel\Money\Money;
use App\Domain\Maintenance\Actions\CreateTyre;
use InvalidArgumentException;

/**
 * A casing as the "new tyre" dialog hands it to {@see CreateTyre} (D1).
 *
 * The price is optional because a tyre often arrives on a truck rather than an invoice: The design stores
 * `purchase_cost_base` as a not-null number, so "not recorded" is zero, and the removal then writes no
 * cost per kilometre rather than a confident 0.0000. A recorded price must be in the base currency —
 * the Action refuses a foreign one instead of inventing a rate.
 *
 * Tread depth is the *new* depth in hundredths of a millimetre (the scale applied to a physical
 * quantity); wear is read off the casing at removal, not stored as a second number here.
 */
final readonly class TyreDto
{
    public function __construct(
        public string $serial,
        public string $size,
        public ?string $brand = null,
        public ?Money $purchaseCost = null,
        public ?int $treadDepthX100 = null,
    ) {
        $serial = trim($serial);

        if ($serial === '') {
            throw new InvalidArgumentException('A tyre needs its serial — it is how the casing is identified for the rest of its life.');
        }

        if (mb_strlen($serial) > 32) {
            throw new InvalidArgumentException('Keep the tyre serial to 32 characters.');
        }

        if (trim($size) === '') {
            throw new InvalidArgumentException('A tyre needs its size, e.g. 315/80R22.5.');
        }

        if (mb_strlen(trim($size)) > 20) {
            throw new InvalidArgumentException('Keep the tyre size to 20 characters.');
        }

        if ($brand !== null && mb_strlen(trim($brand)) > 40) {
            throw new InvalidArgumentException('Keep the brand to 40 characters.');
        }

        if ($purchaseCost !== null && $purchaseCost->minorUnits < 0) {
            throw new InvalidArgumentException('A tyre cannot have cost less than nothing.');
        }

        if ($treadDepthX100 !== null && ($treadDepthX100 <= 0 || $treadDepthX100 > 5000)) {
            throw new InvalidArgumentException('New tread depth is in hundredths of a millimetre, up to 50 mm.');
        }
    }

    public function serialNumber(): string
    {
        return trim($this->serial);
    }

    public function brandName(): ?string
    {
        $brand = $this->brand === null ? '' : trim($this->brand);

        return $brand === '' ? null : $brand;
    }

    public function sizeLabel(): string
    {
        return trim($this->size);
    }
}
