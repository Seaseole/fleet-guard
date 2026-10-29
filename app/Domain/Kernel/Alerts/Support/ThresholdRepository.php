<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Support;

use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Alerts\Models\ReminderThreshold;

/**
 * Reading and establishing `reminder_thresholds` for the company in context (§14.1).
 *
 * `ensureDefaults()` exists because §14.1 made thresholds data: the moment a rule is a row, "the company
 * has no rows yet" is indistinguishable from "the company turned this off". The ensure command gives each
 * company the set its sources declare, once, so an absent family afterwards means what it says — the
 * Owner removed it.
 *
 * It is additive and never destructive. A row the Owner edited is left alone, and a family they deleted
 * is not put back: re-creating what a deliberate act removed is how a maintenance command turns into a
 * second Author.
 */
final class ThresholdRepository
{
    public function forCurrentCompany(): ThresholdSet
    {
        return ThresholdSet::fromRows(ReminderThreshold::query()->get());
    }

    /**
     * @param  iterable<ReminderSource>  $sources
     * @return list<Threshold> the defaults that did not exist before this call
     */
    public function ensureDefaults(iterable $sources): array
    {
        $existing = ReminderThreshold::query()->get()
            ->mapWithKeys(static fn (ReminderThreshold $row): array => [$row->family.':'.$row->days_before => true])
            ->all();

        $created = [];

        foreach ($sources as $source) {
            foreach ($source->defaultThresholds() as $threshold) {
                if (isset($existing[$threshold->family.':'.$threshold->daysBefore])) {
                    continue;
                }

                ReminderThreshold::create([
                    'family' => $threshold->family,
                    'days_before' => $threshold->daysBefore,
                    'recipients' => $threshold->recipients,
                    'active' => true,
                ]);

                $existing[$threshold->family.':'.$threshold->daysBefore] = true;
                $created[] = $threshold;
            }
        }

        return $created;
    }
}
