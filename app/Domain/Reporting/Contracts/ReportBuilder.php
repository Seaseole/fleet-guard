<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Contracts;

use App\Domain\Reporting\Data\ReportData;
use Carbon\CarbonImmutable;

/**
 * The one shape every report builds through: a window in, the seat's own authorised reading of
 * it out (the ReportDefinitions).
 *
 * The window is two dates rather than a kind because every consumer — screen, export job,
 * scheduled mail — asks the same question of a range; the daily report is simply a window whose
 * ends touch.
 */
interface ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData;
}
