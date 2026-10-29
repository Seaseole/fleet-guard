<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Money\Money;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The cell parsers every master-data importer shares.
 *
 * Each returns a `[value, error]` pair: a blank optional cell is `[null, null]`, and anything that
 * cannot be read is `[null, "Label: why"]` so the error report reads like the field it names. The
 * rules are the same ones the typed screens apply, because a CSV row and a form must not be able to
 * disagree about what a date or an amount is.
 */
final class ImportValues
{
    /**
     * A major-unit amount, converted once here (§5.1). The currency is the row's own — a rate or a
     * part cost may be in ZAR — and an unknown code is a row error, not a crash.
     *
     * @return array{0: int|null, 1: string|null}
     */
    public static function money(string $raw, string $currency, string $label): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [null, null];
        }

        try {
            return [Money::ofMajor($raw, $currency)->minorUnits, null];
        } catch (DomainException|InvalidArgumentException|RuntimeException $exception) {
            return [null, "{$label}: {$exception->getMessage()}"];
        }
    }

    /**
     * An ISO calendar date. The round-trip check refuses `2026-02-30`, which Carbon would otherwise
     * roll into March without complaint.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function date(string $raw, string $label, bool $required = false): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $required ? [null, "{$label} is required."] : [null, null];
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            return [null, "{$label} must be a date in YYYY-MM-DD form."];
        }

        // The round trip is what refuses 2026-02-30: Carbon rolls it into March rather than
        // failing, and a stored date two days off is worse than a refused row.
        if ($date->format('Y-m-d') !== $raw) {
            return [null, "{$label} must be a date in YYYY-MM-DD form."];
        }

        return [$raw, null];
    }

    /** @return array{0: int|null, 1: string|null} */
    public static function integer(string $raw, string $label, bool $required = false): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $required ? [null, "{$label} is required."] : [null, null];
        }

        return preg_match('/^-?\d+$/', $raw) === 1
            ? [(int) $raw, null]
            : [null, "{$label} must be a whole number."];
    }

    /** @return array{0: float|null, 1: string|null} */
    public static function decimal(string $raw, string $label): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [null, null];
        }

        if (! is_numeric($raw)) {
            return [null, "{$label} must be a number."];
        }

        return [(float) $raw, null];
    }

    /** @return array{0: bool|null, 1: string|null} */
    public static function flag(string $raw, string $label): array
    {
        $raw = strtolower(trim($raw));

        if ($raw === '') {
            return [null, null];
        }

        if (in_array($raw, ['1', 'true', 'yes', 'y'], true)) {
            return [true, null];
        }

        if (in_array($raw, ['0', 'false', 'no', 'n'], true)) {
            return [false, null];
        }

        return [null, "{$label} must be yes or no."];
    }

    /** @return array{0: string|null, 1: string|null} */
    public static function text(string $raw, int $max, string $label, bool $required = false): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $required ? [null, "{$label} is required."] : [null, null];
        }

        // A silent truncation would change the reference a legacy export joins on, so an over-long
        // value is refused with the field named rather than cut to fit the column.
        if (mb_strlen($raw) > $max) {
            return [null, "{$label} is longer than {$max} characters."];
        }

        return [$raw, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    public static function code(string $raw, int $max, string $label, bool $required = false): array
    {
        [$value, $error] = self::text($raw, $max, $label, $required);

        return [$value === null ? null : strtoupper($value), $error];
    }

    /**
     * Header-key normalisation for matching a row against an existing record.
     *
     * Codes are case-folded with `strtoupper` and names with `strtolower`, so "gbe-wdh" and
     * "GBE-WDH" are one route and "NDL" and "Ndl" are one customer. MySQL's own collation is
     * case-insensitive today; doing it here as well means the in-memory preload and the database
     * lookup agree, which is what keeps a duplicate out of the last row of a large file.
     */
    public static function codeKey(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** @param list<string> $errors */
    public static function push(array &$errors, ?string $error): void
    {
        if ($error !== null) {
            $errors[] = $error;
        }
    }

    /**
     * A whole number inside a column's unsigned-smallint range.
     *
     * @return array{0: int|null, 1: string|null}
     */
    public static function count(string $raw, string $label): array
    {
        [$value, $error] = self::integer($raw, $label);

        if ($error !== null || $value === null) {
            return [$value, $error];
        }

        if ($value < 0 || $value > 65535) {
            return [null, "{$label} must be between 0 and 65 535."];
        }

        return [$value, null];
    }
}
