<?php

declare(strict_types=1);

namespace App\Domain\Kernel\State;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\AuditRecorder;
use BackedEnum;
use Illuminate\Support\Str;

/**
 * The first-party state machine: one transition table per aggregate, every move written to
 * `operational_events` before the column changes.
 *
 * The table lives on the model because that is the only place that cannot drift from the value
 * list the migration's CHECK enforces. The Action decides *whether* a move is allowed (its
 * permissions, its gate conditions); this trait decides whether the move *exists*, records it, and
 * updates the three state columns together so `previous_state` can never disagree with the trail.
 *
 * The event is written first, inside the caller's transaction. If the update then fails, the roll
 * back takes the event with it — no row claims a move that did not happen. Writing the event first
 * also means a trigger or CHECK on the state column cannot leave an unexplained change behind.
 */
trait CanTransition
{
    /**
     * The aggregate's state machine: current state => states reachable in one step.
     *
     * Destinations that are not keys are leaves (closed, sold, cancelled); they still have to
     * appear as values. Listing the table on the model rather than in an Action means a new state
     * is a one-line edit next to the enum it belongs to.
     *
     * @return array<string, list<string>>
     */
    abstract protected function transitions(): array;

    /**
     * Destructive destinations for this aggregate. The design names the categories (void, cancel,
     * abort, reject, write_off, reopened) and the DB CHECK in `operational_events` enforces the
     * reason for the three it knows by name; this list is what stops the caller before it gets
     * that far, with a message that names the aggregate.
     *
     * @return list<string>
     */
    protected function transitionsRequiringReason(): array
    {
        return ['cancelled', 'voided', 'rejected', 'aborted', 'written_off', 'reversed', 'reopened', 'sold', 'disposed', 'retired', 'left'];
    }

    /** The column holding the state. Override per aggregate; every phase-1 table uses `status`. */
    protected function stateAttribute(): string
    {
        return 'status';
    }

    /**
     * `operational_events.aggregate_type` — singular snake of the class, the same convention
     * `failed_job` and `document` already use, so one trail query groups by noun a person
     * recognises rather than by PHP namespace.
     */
    protected function stateAggregateType(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * Check the move without recording it. Screens use this to grey out impossible buttons;
     * the Action still calls `moveTo()`, which asks again.
     */
    public function guardTransition(BackedEnum|string $to, ?string $reason = null): void
    {
        $from = $this->currentStateValue();
        $target = $this->stateValue($to);
        $table = $this->transitions();

        $known = array_keys($table);

        foreach ($table as $destinations) {
            $known = array_merge($known, $destinations);
        }

        if (! in_array($target, $known, true)) {
            throw IllegalTransition::unknownState($this->stateName(), $target);
        }

        $allowed = $table[$from] ?? [];

        if (! in_array($target, $allowed, true)) {
            throw IllegalTransition::notAllowed($this->stateName(), $from, $target, $allowed);
        }

        if (in_array($target, $this->transitionsRequiringReason(), true) && trim((string) $reason) === '') {
            throw IllegalTransition::reasonRequired($this->stateName(), $target);
        }
    }

    /**
     * Move the aggregate: guard, write the event, then the three columns.
     *
     * Named `moveTo` rather than `transitionTo` because `owen-it/laravel-auditing`'s `Auditable`
     * already defines `transitionTo(Audit $audit)` for replaying audits, and two traits with the
     * same method name collide at class load. The state seam owns its own verb.
     *
     * @param  array<string, mixed>  $context
     */
    public function moveTo(
        BackedEnum|string $to,
        ?string $reason = null,
        array $context = [],
        ActorKind $actorKind = ActorKind::User,
        ?int $actorId = null,
    ): void {
        if (! $this->exists) {
            throw IllegalTransition::unsaved($this->stateName());
        }

        $this->guardTransition($to, $reason);

        $from = $this->currentStateValue();
        $target = $this->stateValue($to);

        app(AuditRecorder::class)->operational(
            aggregateType: $this->stateAggregateType(),
            aggregateId: (string) $this->getKey(),
            fromState: $from === '' ? null : $from,
            toState: $target,
            reason: $reason,
            context: $context,
            actorKind: $actorKind,
            actorId: $actorId,
        );

        $this->forceFill([
            $this->stateAttribute() => $target,
            'previous_state' => $from === '' ? null : $from,
            'state_changed_at' => now(),
        ])->save();
    }

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return $this->transitions()[$this->currentStateValue()] ?? [];
    }

    public function canTransitionTo(BackedEnum|string $to): bool
    {
        try {
            $this->guardTransition($to);
        } catch (IllegalTransition) {
            return false;
        }

        return true;
    }

    private function currentStateValue(): string
    {
        $value = $this->getAttribute($this->stateAttribute());

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private function stateValue(BackedEnum|string $to): string
    {
        return $to instanceof BackedEnum ? (string) $to->value : $to;
    }

    private function stateName(): string
    {
        return class_basename(static::class);
    }
}
