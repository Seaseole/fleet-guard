<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Exceptions;

use App\Domain\Kernel\Money\Money;
use RuntimeException;

/**
 * A posting that the ledger refuses to create (layer 1 — the door that is in front of the
 * DB CHECKs, so the caller gets a number rather than an SQLSTATE 3819).
 */
final class PostingRejected extends RuntimeException
{
    public static function unbalanced(int $debit, int $credit, string $currency): self
    {
        // The clerk typed Pula, so the refusal quotes Pula. Raw minor units here read as two
        // extra zeros on every amount, and contradict the balance footer on the same screen.
        $debits = Money::ofMinor($debit, $currency)->format();
        $credits = Money::ofMinor($credit, $currency)->format();
        $difference = Money::ofMinor(abs($debit - $credit), $currency)->format();

        return new self(sprintf(
            'Journal entry is out of balance: debits %s, credits %s, difference %s. '
            .'A ledger entry that does not balance cannot be posted.',
            $debits,
            $credits,
            $difference,
        ));
    }

    public static function noLines(): self
    {
        return new self('A journal entry needs at least one debit and one credit line (CHECK line_count >= 2).');
    }

    public static function oneSided(): self
    {
        return new self('Every journal line has the same direction. A single-sided entry cannot balance, so it cannot post.');
    }

    public static function accountNotPostable(string $code, string $name): self
    {
        return new self("Account {$code} ({$name}) is a rollup header, not a postable account (is_postable).");
    }

    public static function accountInactive(string $code): self
    {
        return new self("Account {$code} is inactive and cannot accept a new posting.");
    }

    public static function accountMissing(int $id): self
    {
        return new self("No gl_accounts row with id {$id} in this company. A line may not point at an account the tenant does not own.");
    }

    /** The screen collected a code rather than an id, and no account carries it. */
    public static function accountCodeUnknown(string $code): self
    {
        return new self(
            "No postable account is coded \"{$code}\" in this company. Check the chart of accounts — ".
            'a line cannot point at an account that does not exist.',
        );
    }

    public static function dimensionRequired(string $code, string $dimension): self
    {
        return new self(sprintf(
            'Account %s requires every line on it to carry %s, and this line carries none ('
            .'allowed_dimension_mask). This is the "fuel line lost its truck and landed in overheads" bug.',
            $code,
            $dimension,
        ));
    }

    public static function mixedBaseCurrency(string $expected, string $got): self
    {
        return new self(sprintf(
            'A journal line carries a %s base amount in a %s entry. amount_base is always the '
            .'company base currency (ADR-0004 #3); the input currency lives in amount_foreign.',
            $got,
            $expected,
        ));
    }

    public static function currencyMismatch(string $lineCurrency, string $entryCurrency): self
    {
        return new self("A line's foreign amount is in {$lineCurrency} but the entry's currency is {$entryCurrency}. One document, one input currency.");
    }

    public static function periodIsLocked(string $period): self
    {
        return new self("The {$period} accounting period is locked. Corrections into a locked period post at today's date as a reversal.");
    }

    public static function zeroAmount(): self
    {
        return new self('A journal line with a zero base amount is not a line: it inflates line_count and unbalances what ledger:verify recomputes (gl_journal_lines_amount_positive).');
    }

    public static function floatAmount(float $amount): self
    {
        return new self(sprintf(
            'A journal line arrived as the float %s. Money is integer minor units — parse the '
            .'major-unit string at the screen boundary and pass the minor units as an int or a digit '
            .'string, because brick/math truncates a float instead of rejecting it.',
            var_export($amount, true),
        ));
    }
}
