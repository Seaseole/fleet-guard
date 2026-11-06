<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Exceptions;

use App\Domain\Kernel\Ledger\Models\JournalEntry;
use RuntimeException;

/** Thrown by {@see JournalEntry} and JournalLine (layer 1). */
final class ImmutableLedgerException extends RuntimeException
{
    public static function cannotUpdate(object $model, string $why): self
    {
        return new self(sprintf(
            'Fleet Guard: %s is immutable (%s). A posted ledger row is corrected with a reversing '
            .'entry, never edited. If this really must change, the audited emergency hatch is '
            .'the gl_ledger_barrier row.',
            class_basename($model),
            $why,
        ));
    }

    public static function cannotDelete(object $model): self
    {
        return new self(sprintf(
            'Fleet Guard: %s rows are never deleted. The only write path is LedgerService::post() '
            .'and the only correction is a reversal.',
            class_basename($model),
        ));
    }
}
