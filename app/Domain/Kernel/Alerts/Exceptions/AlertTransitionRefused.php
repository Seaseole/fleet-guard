<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Exceptions;

use App\Domain\Kernel\Alerts\AlertState;
use RuntimeException;

/**
 * A move the §10 alert machine does not allow, or one it allows only with a reason that is missing.
 *
 * Named rather than an `InvalidArgumentException` on an enum because the message is the product: the
 * clerk who tries to resolve a muted alert without saying how it was dealt with needs to be told that
 * the sentence they skipped is the reason the column exists, and the developer who reaches for
 * `$alert->state = 'open'` needs the same sentence. §10.1's rule is that an illegal transition is
 * refused *and* a legal one is recorded — this is the refusal half, and it never writes an event,
 * because nothing happened.
 */
final class AlertTransitionRefused extends RuntimeException
{
    public static function between(AlertState $from, AlertState $to, int $alertId): self
    {
        return new self(sprintf(
            'An alert cannot go from %s to %s (#%d). Allowed from %s: %s (§10).',
            $from->value,
            $to->value,
            $alertId,
            $from->value,
            $from->allowedTransitions() === []
                ? 'nothing — a resolved alert is history, and a recurrence is a new alert'
                : implode(', ', array_map(static fn (AlertState $s): string => $s->value, $from->allowedTransitions())),
        ));
    }

    public static function withoutReason(AlertState $to, int $alertId): self
    {
        return new self(sprintf(
            'Moving alert #%d to %s needs a reason. §10.1 makes it mandatory for anything that says an alert can be '
            .'ignored or is finished, and the alerts_%s_explained CHECK refuses the row without one — the database will '
            .'not let this through, so the screen must not either.',
            $alertId,
            $to->value,
            $to === AlertState::Resolved ? 'resolved' : 'muted',
        ));
    }
}
