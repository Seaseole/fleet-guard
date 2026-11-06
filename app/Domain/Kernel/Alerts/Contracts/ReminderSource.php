<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Contracts;

use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use Carbon\CarbonInterface;

/**
 * Something the engine asks "what needs attention?" — the seam that lets Phase 1 add a source without
 * the alert engine ever naming a module.
 *
 * The D2 rule says the Kernel may not import a module. Read as a constraint on the reminder engine
 * that looks like a problem — expiry conditions live in Fleet, Maintenance and Invoicing, none of
 * which exist yet. It is not a problem, it is the design: the engine holds this interface, each module
 * implements it, and the container hands the engine the list. The engine then knows that *something*
 * reports expiring service schedules; it never knows what a `ServiceSchedule` is. The direction of the
 * dependency is the one the folder contract allows.
 *
 * A source answers for the company in `CurrentCompany`'s context and nothing else. The runner pins that
 * context per company (T4) before calling, which is what makes every read inside a source
 * tenant-scoped by the normal `CompanyScope`, and why a source that reached for
 * `withoutGlobalScopes()` would be reporting one company's papers into another's alerts.
 */
interface ReminderSource
{
    /**
     * Stable identity for the source, used in logs and by `reminders:ensure-thresholds`.
     */
    public function key(): string;

    /**
     * The threshold rows a company starts with, before the Owner edits them.
     *
     * Defaults live on the source rather than in a central seeder so that adding a module adds its own
     * reminders and nothing has to remember to edit a file in another module — the same argument
     * makes for thresholds being rows.
     *
     * @return list<Threshold>
     */
    public function defaultThresholds(): array;

    /**
     * Every condition true on this business day, for the buckets the company actually configured.
     *
     * The set is passed in rather than queried per candidate because the two must agree: a source that
     * invented its own windows would raise alerts no threshold authorised, and the "not constants"
     * would be a comment rather than a rule.
     *
     * @return iterable<ReminderCandidate>
     */
    public function candidates(ThresholdSet $thresholds, CarbonInterface $businessToday): iterable;
}
