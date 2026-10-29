<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Scan;

use App\Domain\Kernel\Documents\ScanVerdict;

/**
 * What a scanner says about one file: the verdict, and the detail worth showing a human.
 *
 * The detail is mandatory for `infected` (which signature, so the owner can judge whether it is a
 * real find or a stale database) and is where the reason lives for `pending` when a scanner was
 * configured but did not answer — a verdict with no explanation is a status light, not a record.
 */
final class ScanResult
{
    public function __construct(
        public readonly ScanVerdict $verdict,
        public readonly ?string $detail = null,
    ) {}

    public static function pending(?string $why): self
    {
        return new self(ScanVerdict::Pending, $why);
    }

    public static function clean(): self
    {
        return new self(ScanVerdict::Clean);
    }

    public static function infected(string $signature): self
    {
        return new self(ScanVerdict::Infected, $signature);
    }
}
