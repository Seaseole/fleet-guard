<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use Carbon\CarbonInterface;

/**
 * One condition, as a source saw it on one business day — the only thing a module is allowed to hand
 * the engine.
 *
 * This is the seam that keeps §13's D2 (the Kernel is a leaf) true once Phase 1 arrives. A source that
 * returned `Truck` or `Invoice` models would make the alert engine depend on modules that do not exist
 * yet, and the folder contract's whole argument is that the direction of that dependency is the one
 * that breaks. A candidate carries *words and ids*, not objects: the register renders them from
 * `payload`, and the deep link a later phase adds resolves them.
 *
 * `family` and `daysBefore` travel with the candidate because the recipients come from the threshold
 * row that produced it, not from the alert type. `doc_expiry` says what kind of alert this is; the row
 * named `licence` at 30 days says who is told.
 */
final readonly class ReminderCandidate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AlertType $type,
        public AlertSeverity $severity,
        public string $family,
        public ?int $daysBefore,
        public ?string $subjectType,
        public ?string $subjectId,
        public CarbonInterface $occurredOn,
        public string $bucket,
        public string $title,
        public array $payload = [],
    ) {}

    /**
     * The key §14.1's cooldown is measured against, before the episode suffix.
     *
     * `type:subject:bucket` is §14.1's `type + subject + bucket` written out. The subject is included
     * even though the bucket usually names it too, because the uniqueness that matters is "this
     * condition on this row", and a bucket built from a date (`30d:2026-12-01`) is the same string for
     * two trucks whose licences expire together.
     */
    public function baseCooldownKey(): string
    {
        return implode(':', [
            $this->type->value,
            $this->subjectType ?? '-',
            $this->subjectId ?? '-',
            $this->bucket,
        ]);
    }
}
