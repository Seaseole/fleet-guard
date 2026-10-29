<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

/**
 * What one reminder run did, in the numbers a reviewer needs rather than the ones that read well.
 *
 * `unalerted` is the reason this class exists. A run that raised eleven alerts and told nobody looks
 * identical, in a log line, to a run that raised eleven and emailed the Operations Manager — and the
 * second is the one §14.1 promises. The command therefore exits 1 when this list is not empty: "a
 * reminder nobody receives is a defect, not a quiet success", which is slice 7's *silence is loud* rule
 * applied to the reminder path instead of to the ledger.
 */
final class ReminderRunReport
{
    /**
     * @var list<string>
     */
    public array $unalerted = [];

    public int $companies = 0;

    public int $candidates = 0;

    public int $raised = 0;

    public int $refreshed = 0;

    public int $escalated = 0;

    public int $deliveredTo = 0;

    public int $mutesReopened = 0;

    public int $companiesWithoutThresholds = 0;

    public function addUnalerted(string $finding): void
    {
        $this->unalerted[] = $finding;
    }

    public function succeeded(): bool
    {
        return $this->unalerted === [];
    }

    public function summary(): string
    {
        return sprintf(
            '%d compan%s, %d condition%s, %d raised, %d refreshed (%d escalated), %d delivery target%s, %d mute%s reopened%s',
            $this->companies,
            $this->companies === 1 ? 'y' : 'ies',
            $this->candidates,
            $this->candidates === 1 ? '' : 's',
            $this->raised,
            $this->refreshed,
            $this->escalated,
            $this->deliveredTo,
            $this->deliveredTo === 1 ? '' : 's',
            $this->mutesReopened,
            $this->mutesReopened === 1 ? '' : 's',
            $this->companiesWithoutThresholds > 0
                ? ', '.$this->companiesWithoutThresholds.' with no thresholds configured'
                : '',
        );
    }
}
