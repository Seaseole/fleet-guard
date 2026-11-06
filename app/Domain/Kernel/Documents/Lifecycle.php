<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

/**
 * The stored axis: `draft → current → superseded → archived`, plus `voided` reachable from
 * either live state.
 *
 * The transition table is a method rather than a column because the rule is that an illegal
 * transition is refused in code and recorded as an event when it is legal; a screen can only do that
 * if the answer lives in one place.
 *
 * **One reading of the design has to be chosen here, and this is it.** The section says
 * `current → superseded` "happens automatically when a new version is published". Taken literally
 * about the *document*, that makes a document that receives a second version no longer current — so
 * the truck's licence would read "superseded" on the register the moment somebody re-uploaded a
 * clearer scan of it, and the expiry board would have nothing current to alert on. The version is
 * what is superseded (the `is_current` pointer moves); the document stays `current`. A document
 * reaches `superseded` when the paper it evidences is replaced by *another document* — the renewed
 * licence filed beside the expired one — which is a decision, and therefore a stored state, and
 * therefore not automatic.
 */
enum Lifecycle: string
{
    case Draft = 'draft';
    case Current = 'current';
    case Superseded = 'superseded';
    case Archived = 'archived';
    case Voided = 'voided';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Current, self::Voided],
            self::Current => [self::Superseded, self::Archived, self::Voided],
            // A superseded document can still be archived or voided: the paper it holds is
            // evidence of what was on file at the time, and a void found later voids the record
            // of it, not just the copy that happens to be current.
            self::Superseded => [self::Archived, self::Voided],
            self::Archived => [],
            self::Voided => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Whether new bytes may be filed against a document in this state.
     *
     * Draft and current only. A superseded, archived or voided document is a record of a decision,
     * and letting an upload slip a new copy into it would change what that record says without any
     * transition, event or actor — which is the hole the lifecycle exists to close. The
     * successor document is where the new paper belongs.
     */
    public function acceptsNewVersion(): bool
    {
        return $this === self::Draft || $this === self::Current;
    }

    /** Is this a state that the destructive-reason rule applies to? */
    public function isDestructive(): bool
    {
        return $this === self::Voided;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Current => 'Current',
            self::Superseded => 'Superseded',
            self::Archived => 'Archived',
            self::Voided => 'Voided',
        };
    }
}
