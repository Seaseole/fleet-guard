<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Data;

/**
 * One metric as Phase 8 demands: a written definition and the source query it reads,
 * beside the unit a figure in it carries.
 *
 * The definition is not decoration — it is the sentence that settles an argument about a number.
 * A metric whose words and whose query can disagree is the second source of truth this catalogue
 * exists to prevent (the derivation rule, asked of reports).
 */
final class Metric
{
    /**
     * @param  list<string>  $reportKeys
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $definition,
        public readonly string $source,
        public readonly string $unit,
        public readonly array $reportKeys,
    ) {}
}
