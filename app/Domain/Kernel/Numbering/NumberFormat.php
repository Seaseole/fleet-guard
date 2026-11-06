<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

/**
 * Renders a document number from its format (the `format` column), e.g.
 * `INV-{PERIOD}-{SEQ:7}` + period `FY26/27` + value 42 → `INV-FY26/27-0000042`.
 *
 * Padding is a minimum width, never a truncation: a sequence that outgrows its pad prints in
 * full. Silently clipping a fiscal number would collide with an already-issued document.
 */
final class NumberFormat
{
    private const TOKENS = '/\{(?P<name>PERIOD|SEQ)(?::(?P<arg>\d+))?\}/';

    /**
     * A format is renderable only if every brace group is a known token and the counter is
     * present. A typo like `{PERIODD}` or `{SEQ:7` would otherwise be written into a legal
     * fiscal document and only noticed at BRBS.
     */
    public static function validate(string $format): void
    {
        $remainder = preg_replace(self::TOKENS, '', $format) ?? $format;

        if (str_contains($remainder, '{') || str_contains($remainder, '}')) {
            throw NumberingError::malformedFormat($format);
        }

        // Without a counter there is nothing to increment, so "gap-free" would be meaningless.
        if (preg_match('/\{SEQ(?::\d+)?\}/', $format) !== 1) {
            throw NumberingError::malformedFormat($format);
        }
    }

    public static function render(string $format, string $periodKey, int $value): string
    {
        self::validate($format);

        return (string) preg_replace_callback(
            self::TOKENS,
            function (array $match) use ($periodKey, $value): string {
                if ($match['name'] === 'PERIOD') {
                    return $periodKey;
                }

                $width = (int) ($match['arg'] ?? 0);

                return $width > 0
                    ? str_pad((string) $value, $width, '0', STR_PAD_LEFT)
                    : (string) $value;
            },
            $format,
        );
    }
}
