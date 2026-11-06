<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use InvalidArgumentException;

/**
 * One load as the loading schedule enters it (LOAD).
 *
 * `tonnes` arrives as the typed **string** a person entered and is parsed exactly once, here — the same
 * Rule the money columns follow, applied to the one physical quantity that decides whether a
 * truck crosses a weighbridge. The parsed float is the only tonnage the rest of the system ever sees,
 * so a limit check cannot disagree with the validation message above it.
 *
 * A zero-tonnage load is refused rather than rounded away: an empty row on the schedule is how a
 * waybill line gets lost, and the gate measures against the total.
 */
final readonly class LoadDto
{
    public float $tonnes;

    public ?float $cubicMeters;

    public function __construct(
        public string $commodity,
        string $tonnes,
        public ?string $consignee = null,
        ?string $cubicMeters = null,
        public ?string $origin = null,
        public ?string $destination = null,
        public ?string $stopSequence = null,
        public ?string $waybillDocumentId = null,
    ) {
        if (trim($commodity) === '') {
            throw new InvalidArgumentException('A load needs a commodity — "stuff" cannot be priced, insured or cleared.');
        }

        $this->tonnes = self::number($tonnes, 'Tonnage');

        if ($this->tonnes <= 0) {
            throw new InvalidArgumentException('A load must weigh something. Remove the row instead of booking it at zero.');
        }

        $this->cubicMeters = $cubicMeters === null ? null : self::number($cubicMeters, 'Volume');

        if ($this->cubicMeters !== null && $this->cubicMeters < 0) {
            throw new InvalidArgumentException('Volume cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            commodity: (string) ($input['commodity'] ?? ''),
            tonnes: (string) ($input['tonnes'] ?? ''),
            consignee: self::text($input['consignee'] ?? null),
            cubicMeters: self::text($input['cubic_meters'] ?? null),
            origin: self::text($input['origin'] ?? null),
            destination: self::text($input['destination'] ?? null),
            stopSequence: self::text($input['stop_sequence'] ?? null),
            waybillDocumentId: self::text($input['waybill_document_id'] ?? null),
        );
    }

    /**
     * Accepts what a person types — `3.5`, `3,5`, `1 250` — and nothing else.
     *
     * Rejects rather than repairs: `3.5t` and `~4` are both guesses about a legal limit, and the
     * weighbridge is where a guess becomes someone else's problem.
     */
    private static function number(string $value, string $label): float
    {
        $clean = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($value));
        $clean = str_replace(',', '.', $clean);

        if (! is_numeric($clean)) {
            throw new InvalidArgumentException("{$label} \"{$value}\" is not a number.");
        }

        return (float) $clean;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
