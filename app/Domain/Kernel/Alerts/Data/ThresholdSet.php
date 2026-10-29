<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

use App\Domain\Kernel\Alerts\Models\ReminderThreshold;
use Countable;
use Illuminate\Support\Collection;

/**
 * Every live threshold row for one company, indexed by family.
 *
 * It is a set rather than a query because the reminder path asks the same question three ways per
 * candidate — is this family configured, which buckets does it want, who receives this one — and a
 * company with ten families and four buckets each should not cost forty queries in a nightly job that
 * is supposed to be boring.
 *
 * A family with **no rows here has no reminders**, and that is §14.1 working as written: the rows are
 * the contract, so deleting one deletes the reminder. `reminders:ensure-thresholds` is what gives a
 * company the default set, so an absent family is always an owner's decision rather than an oversight.
 */
final readonly class ThresholdSet implements Countable
{
    /**
     * @param  array<string, array<int, Threshold>>  $byFamily  family => days_before => threshold
     */
    private function __construct(private array $byFamily) {}

    /**
     * @param  Collection<int, ReminderThreshold>  $rows
     */
    public static function fromRows(Collection $rows): self
    {
        $byFamily = [];

        foreach ($rows as $row) {
            // `active` is the half of the row the Owner gets to flip without deleting the history of
            // what the company had configured; an inactive row must answer "not configured".
            if (! $row->active) {
                continue;
            }

            $byFamily[$row->family][$row->days_before] = new Threshold(
                $row->family,
                $row->days_before,
                $row->recipientRoleKeys(),
            );
        }

        return new self($byFamily);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @return list<string> */
    public function families(): array
    {
        return array_keys($this->byFamily);
    }

    public function has(string $family): bool
    {
        return isset($this->byFamily[$family]);
    }

    /**
     * The configured buckets for a family, furthest horizon first.
     *
     * Ordered so a licence ninety days out that also matches a sixty-day row reports the widest
     * window it is inside: the buckets overlap by design (a document 25 days out is inside the 60-day
     * window too), and the first match is the one the Owner configured as "early enough".
     *
     * @return list<int>
     */
    public function buckets(string $family): array
    {
        $days = array_keys($this->byFamily[$family] ?? []);
        rsort($days);

        return $days;
    }

    public function find(string $family, int $daysBefore): ?Threshold
    {
        return $this->byFamily[$family][$daysBefore] ?? null;
    }

    /** @return list<Threshold> */
    public function all(): array
    {
        $rows = [];

        foreach ($this->byFamily as $thresholds) {
            foreach ($thresholds as $threshold) {
                $rows[] = $threshold;
            }
        }

        usort($rows, static fn (Threshold $a, Threshold $b): int => $b->daysBefore <=> $a->daysBefore);

        return $rows;
    }

    public function count(): int
    {
        return count($this->all());
    }
}
