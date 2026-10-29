<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * §10.9's border-crossing machine: `planned → entered → exited → costs_captured`, and the two Phase 6
 * states after it.
 *
 * The last two are in the enum because the database CHECK lists the full vocabulary, but no Action in
 * this build may reach them: `agent_reconciled` needs an advance to match and `closed` needs the
 * reconciliation to agree, and Phase 6 owns both. {@see self::isReachableHere()} is what keeps a
 * future caller honest rather than the absence of a string.
 */
enum BorderCrossingStatus: string
{
    case Planned = 'planned';
    case Entered = 'entered';
    case Exited = 'exited';
    case CostsCaptured = 'costs_captured';
    case AgentReconciled = 'agent_reconciled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'planned',
            self::Entered => 'entered',
            self::Exited => 'exited',
            self::CostsCaptured => 'costs captured',
            self::AgentReconciled => 'agent reconciled',
            self::Closed => 'closed',
        };
    }

    /** @return 'neutral'|'warning'|'success'|'danger' */
    public function tone(): string
    {
        return match ($this) {
            self::Planned => 'neutral',
            self::Entered => 'warning',
            self::Exited => 'accent',
            self::CostsCaptured => 'success',
            self::AgentReconciled, self::Closed => 'success',
        };
    }

    /** Whether this build's Actions may move a crossing into the given state (§15.2 keeps Phase 6 shut). */
    public function isReachableHere(): bool
    {
        return in_array($this, [self::Entered, self::Exited, self::CostsCaptured], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
