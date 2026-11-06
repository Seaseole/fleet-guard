<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Actions;

use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\Data\RaiseOutcome;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The only door through which an alert comes into existence.
 *
 * One door for the reason the design gives for the numbering allocator and slice 7 gives for the audit trail:
 * the fields that make the row worth reading — the cooldown key, the business day, the first-seen
 * stamp — are the ones a busy call site gets wrong or omits, and an alert with a key nobody derived the
 * same way is a duplicate that looks like a second problem.
 *
 * **Cooldown is a lookup, not a counter.** The design forbids emailing the same expiring licence fourteen
 * times, and the naive way to honour that is a `cooldown_until` timestamp a nightly job compares
 * against `now()`. That version silently expires and the fourteenth email arrives anyway, eighteen days
 * later. Here the condition's *identity* is the key: if a live alert already carries that key, there is
 * nothing to tell anybody, and the run only records that the condition is still true.
 *
 * **A resolved alert does not hold the key.** The machine says `resolved` is final ({@see
 * AlertState::allowedTransitions()}), so a check that was fixed and then failed again is a second
 * occurrence and needs its own row — otherwise the register would show one resolved alert forever while
 * the same digest mismatch quietly returned, and "when did this start?" would have the wrong answer.
 * The episode suffix (`base#2`) is what makes that legal under the unique index. The keys are probed
 * exactly, never with `LIKE`: The design's own type names contain underscores, and in a `LIKE` pattern an
 * underscore matches any character, so a prefix search would let `doc_expiry` collide with
 * `docXexpiry` and inflate episode numbers for rows that have nothing to do with each other.
 *
 * Concurrency is answered by the index rather than by a lock: two overlapping runs computing the same
 * key get one insert and one `UniqueConstraintViolationException`, and the loser refreshes the winner's
 * row instead of losing its finding.
 */
final class RaiseAlert
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ReminderCandidate $candidate): RaiseOutcome
    {
        $base = $candidate->baseCooldownKey();

        /** @var Alert|null $live */
        $live = Alert::query()->live()->where('cooldown_key', $base)->first();

        if ($live !== null) {
            return $this->refresh($live, $candidate);
        }

        foreach (Alert::episodeKeys($base) as $index => $key) {
            /** @var Alert|null $existing */
            $existing = Alert::query()->where('cooldown_key', $key)->first();

            if ($existing === null) {
                return $this->insertNew($candidate, $key, $index + 1);
            }

            // Found a live row on a suffixed key: another process created it between the lookup and
            // this one, or the sweep is running twice. Either way the condition is already on the
            // register, and refreshing it is the whole answer.
            if ($existing->isLive()) {
                return $this->refresh($existing, $candidate);
            }
        }

        throw new RuntimeException(sprintf(
            'The condition %s has been resolved and re-raised %d times. Resolve it properly or mute it with an '
            .'expiry; a row per occurrence has stopped telling anyone anything.',
            $base,
            Alert::Episodes,
        ));
    }

    private function insertNew(ReminderCandidate $candidate, string $key, int $episode): RaiseOutcome
    {
        $now = now();

        try {
            /** @var Alert $alert */
            $alert = Alert::forceCreate([
                'type' => $candidate->type,
                'severity' => $candidate->severity,
                'subject_type' => $candidate->subjectType,
                'subject_id' => $candidate->subjectId,
                'occurred_on' => $candidate->occurredOn,
                'cooldown_key' => $key,
                'payload' => $candidate->payload + ['title' => $candidate->title, 'bucket' => $candidate->bucket],
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'notify_count' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race for this key. The winner's row is the finding; refresh it rather than
            // reporting that the alert did not happen.
            /** @var Alert $winner */
            $winner = Alert::query()->where('cooldown_key', $key)->firstOrFail();

            return $this->refresh($winner, $candidate);
        }

        // The raise is a state event like any other, and it is recorded with the principal that
        // actually made it. A trail whose system-generated rows are attributed to whoever happened to be
        // signed in is the queue-worker leak wearing a different costume.
        $this->audit->operational(
            'alert',
            (string) $alert->id,
            null,
            AlertState::Open->value,
            null,
            [
                'type' => $candidate->type->value,
                'family' => $candidate->family,
                'days_before' => $candidate->daysBefore,
                'cooldown_key' => $key,
                'episode' => $episode,
                'subject_type' => $candidate->subjectType,
                'subject_id' => $candidate->subjectId,
            ],
            ActorKind::Scheduler,
        );

        return new RaiseOutcome($alert, true);
    }

    /**
     * The condition is already on the register. Move its clock, keep its history, and say whether this
     * run is worth telling anybody about.
     *
     * Only `last_seen_at` and the band the condition now sits in move. `notify_count` counts deliveries,
     * not sightings, and mixing the two would make "we told the dispatcher six times" indistinguishable
     * from "this has been failing for six nights".
     *
     * Escalation is what the one-row-per-condition design pays for, and it is measured against the row's
     * own last known state: a licence that has crossed from the 30-day band into the 14-day one, or from
     * `expiring_soon` into `expired`, is a different fact about the world and goes out again. A third
     * night of the same band does not.
     */
    private function refresh(Alert $alert, ReminderCandidate $candidate): RaiseOutcome
    {
        $previousSeverity = $alert->severity;
        $previousBand = $alert->payload['band_days'] ?? null;

        $escalated = $candidate->severity->rank() < $previousSeverity->rank()
            || (is_int($previousBand) && $candidate->daysBefore !== null && $candidate->daysBefore < $previousBand);

        $alert->forceFill([
            'last_seen_at' => now(),
            'severity' => $escalated ? $candidate->severity : $alert->severity,
            'payload' => $candidate->payload + ['title' => $candidate->title, 'bucket' => $candidate->bucket],
        ])->save();

        return new RaiseOutcome($alert, false, true, $escalated);
    }
}
