<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Commercial;

/**
 * The payment-terms vocabulary shared by customers and suppliers.
 *
 * One place, because a term is the same fact on both sides of the ledger: `cash` or `net_<days>`.
 * The alternative — a format checked in the customer DTO and a second copy on the supplier side —
 * is how "net_30 " with a trailing space gets accepted on one screen and refused on the other.
 *
 * Deliberately not an enum: the days are a negotiation, not a fixed list, and an owner agreeing
 * `net_45` must not wait for a deploy. The stored value stays a short string (the migration
 * explains why), and this class is the only reader that interprets it.
 */
final class PaymentTerms
{
    public const DEFAULT = 'net_30';

    /** `cash`, or net days within a year. `net_0` is not a term — it is cash. */
    public const PATTERN = '/^(?:cash|net_[1-9][0-9]{0,2})$/';

    public static function normalise(string $terms): string
    {
        return strtolower(trim($terms));
    }

    public static function isValid(string $terms): bool
    {
        return preg_match(self::PATTERN, self::normalise($terms)) === 1;
    }

    /** "Cash on delivery", "Net 30 days" — how an invoice footer reads a stored term. */
    public static function label(string $terms): string
    {
        $terms = self::normalise($terms);

        if ($terms === 'cash') {
            return 'Cash on delivery';
        }

        if (preg_match('/^net_([1-9][0-9]{0,2})$/', $terms, $matches) === 1) {
            return 'Net '.$matches[1].' days';
        }

        // An unparseable term is shown as stored rather than guessed at: it is a data problem the
        // accountant should see, not one to hide behind a default.
        return $terms;
    }
}
