<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

/**
 * One line of the integrity report. A class rather than an array so the command cannot quietly
 * start treating "unchecked" as success: the three states are named.
 */
final readonly class Finding
{
    public const Ok = 'ok';

    public const Drift = 'drift';

    public const Unchecked = 'unchecked';

    public function __construct(
        public int $companyId,
        public string $scope,
        public string $periodKey,
        public string $status,
        public string $detail,
    ) {}

    public function isDrift(): bool
    {
        return $this->status === self::Drift;
    }

    public function isUnchecked(): bool
    {
        return $this->status === self::Unchecked;
    }
}
