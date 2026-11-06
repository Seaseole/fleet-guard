<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

/**
 * One row of `reminder_thresholds`, as the engine reads it.
 *
 * A value object rather than the Eloquent model because the reminder path runs inside a loop over
 * every company and must not hand a caller a live model it could save. `recipients` are role keys,
 * never user ids — the promise is that what a person receives follows the role they hold, so when
 * the Accountant changes the reminders follow the role rather than the inbox of whoever held it in
 * March.
 */
final readonly class Threshold
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public string $family,
        public int $daysBefore,
        public array $recipients,
    ) {}

    /**
     * The cooldown bucket this threshold produces.
     *
     * `{days}d` rather than the bare number so a key that names a threshold and a key that names
     * something else entirely cannot collide — see {@see ReminderCandidate::baseCooldownKey()}.
     */
    public function bucket(): string
    {
        return $this->daysBefore.'d';
    }
}
