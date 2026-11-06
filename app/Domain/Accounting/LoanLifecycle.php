<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The loan machine: draft → submitted → approved → disbursed → servicing → closed, with
 * servicing ⇄ arrears, arrears → defaulted → written_off | recovered, servicing → restructured
 * (new schedule rows appended; history preserved), and approved → cancelled only before any
 * money moved.
 *
 * The transitions themselves live in the Actions — an enum cannot check that a disbursement
 * posted, and a lifecycle that lets `canMoveTo()` pretend otherwise is how statuses start
 * lying. What lives here is the *vocabulary*, the editability rule, and the two predicates the
 * rest of the module asks constantly: has money moved, is this still owed.
 */
enum LoanLifecycle: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
    case Disbursed = 'disbursed';
    case Servicing = 'servicing';
    case Arrears = 'arrears';
    case Restructured = 'restructured';
    case Closed = 'closed';
    case Defaulted = 'defaulted';
    case WrittenOff = 'written_off';
    case Recovered = 'recovered';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
            self::Disbursed => 'Disbursed',
            self::Servicing => 'Servicing',
            self::Arrears => 'In arrears',
            self::Restructured => 'Restructured',
            self::Closed => 'Closed',
            self::Defaulted => 'Defaulted',
            self::WrittenOff => 'Written off',
            self::Recovered => 'Recovered',
        };
    }

    /** Only the paper before approval can be edited; from submission the terms are the ones signed. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Cancel is only for a loan whose money never moved — the loans CHECK says it too. */
    public function canStillCancel(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved], true);
    }

    /** The states where the facility still owes money — the 13-week forecast reads this list. */
    public function isOwed(): bool
    {
        return in_array($this, [self::Disbursed, self::Servicing, self::Arrears, self::Restructured], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
