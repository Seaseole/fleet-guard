<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Data;

use Illuminate\Support\Facades\Gate;

/**
 * One report as the library knows it: who may read it, who may export it, which window it
 * takes, and the metrics whose written definitions stand underneath the figures (the
 * catalogue: the number and the sentence about the number travel together).
 *
 * `viewGate` is an *additional* ability on top of `reports.view` — the matrix the design gives the
 * dispatcher ops only, the workshop only the workshop's, and a report column nobody may see is
 * a leak the export would carry out the side door.
 */
final class ReportDefinition
{
    /**
     * @param  list<string>  $metricKeys
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $category,
        public readonly ?string $viewGate,
        public readonly string $exportAbility,
        public readonly string $param,
        public readonly string $builder,
        public readonly array $metricKeys,
        public readonly bool $schedulable = false,
    ) {}

    public function isDayWindow(): bool
    {
        return $this->param === 'day';
    }

    /**
     * The seat-facing half of the rule, living once on the definition: the library list,
     * the chosen-report guard and the export door all ask this method rather than each
     * re-writing the gate expression — a rule written twice is a rule that will drift.
     */
    public function visibleToCurrentSeat(): bool
    {
        return $this->viewGate === null || Gate::allows($this->viewGate);
    }
}
