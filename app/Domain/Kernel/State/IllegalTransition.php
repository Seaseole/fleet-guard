<?php

declare(strict_types=1);

namespace App\Domain\Kernel\State;

use RuntimeException;

/**
 * A state move the aggregate refuses: not in the table, a state that is not part of the machine,
 * a destructive target without its reason, or an aggregate that has not been saved yet.
 *
 * Each factory carries the aggregate's own name so the message on a screen reads "Truck cannot
 * move…" rather than "the model". The screen shows the exception message directly, which is why
 * these are sentences and not codes.
 */
final class IllegalTransition extends RuntimeException
{
    /** @param list<string> $allowed */
    public static function notAllowed(string $aggregate, string $from, string $to, array $allowed): self
    {
        $options = $allowed === [] ? 'nothing' : implode(', ', $allowed);

        return new self("{$aggregate} cannot move from '{$from}' to '{$to}'. From there it may go to: {$options}.");
    }

    public static function unknownState(string $aggregate, string $to): self
    {
        return new self("'{$to}' is not a state of {$aggregate} — the transition table has no row for it.");
    }

    public static function reasonRequired(string $aggregate, string $to): self
    {
        return new self("Moving {$aggregate} to '{$to}' is destructive (§10.1), so it needs a reason that will appear in the operational trail.");
    }

    public static function unsaved(string $aggregate): self
    {
        return new self("{$aggregate} has not been saved, so it has no state to move from. Create the row first, then transition it.");
    }
}
