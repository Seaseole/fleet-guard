<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Data;

/**
 * What one webhook batch did (the ingest path).
 *
 * The three outcomes are kept apart because they mean different things to the person reading a
 * provider's support ticket: `duplicates` is the retry guard working (the message was already on
 * file), while `rejected` is a message that was malformed or named equipment this company does not
 * have. Lumping them into "skipped" would make a provider that repeats every message indistinguishable
 * from one that sends garbage.
 */
final readonly class IngestReport
{
    public function __construct(
        public int $received,
        public int $stored,
        public int $duplicates,
        public int $rejected,
    ) {}

    public function skipped(): int
    {
        return $this->duplicates + $this->rejected;
    }

    /** Compact line for a command's output, and the same words the verifier prints. */
    public function summary(): string
    {
        return sprintf(
            '%d received, %d stored, %d duplicate(s), %d rejected',
            $this->received,
            $this->stored,
            $this->duplicates,
            $this->rejected,
        );
    }
}
