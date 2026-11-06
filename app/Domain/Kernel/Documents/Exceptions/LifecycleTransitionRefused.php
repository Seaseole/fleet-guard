<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Exceptions;

use App\Domain\Kernel\Documents\Lifecycle;
use RuntimeException;

/**
 * A lifecycle move the table does not allow.
 *
 * Named rather than an `InvalidArgumentException` on an enum because the message is the product: a
 * dispatcher who voids a document and then tries to archive it needs to be told that a void record
 * is already retired, and a developer who reaches for `$document->lifecycle = 'draft'` needs the same
 * sentence. The rule is that an illegal transition is refused *and* a legal one is recorded —
 * this is the refusal half, and it never writes an event, because nothing happened.
 */
final class LifecycleTransitionRefused extends RuntimeException
{
    public static function between(Lifecycle $from, Lifecycle $to, string $documentId): self
    {
        return new self(sprintf(
            'A document cannot go from %s to %s (%s). Allowed from %s: %s.',
            $from->value,
            $to->value,
            $documentId,
            $from->value,
            $from->allowedTransitions() === []
                ? 'nothing — this state is final'
                : implode(', ', array_map(static fn (Lifecycle $l): string => $l->value, $from->allowedTransitions())),
        ));
    }

    public static function newVersionOn(Lifecycle $state, string $documentId): self
    {
        return new self(sprintf(
            'New bytes cannot be filed against a %s document (%s). A %s record is closed; the successor document is where a newer copy belongs.',
            $state->value,
            $documentId,
            $state->value,
        ));
    }

    public static function withoutReason(Lifecycle $to, string $documentId): self
    {
        return new self(sprintf(
            'Moving to %s on document %s needs a reason. The design makes it mandatory for a destructive transition and the '
            .'operational_events CHECK refuses the row without one — the database will not let this through, so the screen must not either.',
            $to->value,
            $documentId,
        ));
    }
}
