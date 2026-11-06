<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * What kind of reconciling line an item is (Phase 5's classic bank-reconciliation list).
 *
 * The kind is a label for the reader and a *suggested* direction; the direction each item
 * actually moves in is stored per row (see {@see ReconcilingItemDirection}) because a book error
 * and an FX adjustment can run either way, and an accountant should not have to remember which
 * convention the code guessed.
 */
enum ReconcilingItemKind: string
{
    case DepositOutstanding = 'deposit_outstanding';
    case ChequeUnpresented = 'cheque_unpresented';
    case BankCharge = 'bank_charge';
    case InterestEarned = 'interest_earned';
    case FxAdjustment = 'fx_adjustment';
    case BookError = 'book_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DepositOutstanding => 'Deposit in transit',
            self::ChequeUnpresented => 'Unpresented cheque',
            self::BankCharge => 'Bank charge not in the book',
            self::InterestEarned => 'Interest not in the book',
            self::FxAdjustment => 'Exchange adjustment',
            self::BookError => 'Book error',
            self::Other => 'Other',
        };
    }

    public function suggestedDirection(): ReconcilingItemDirection
    {
        return match ($this) {
            // The book already counted the money; the bank has not — expected statement is lower.
            self::DepositOutstanding => ReconcilingItemDirection::Subtract,
            // The book already paid it out; the bank has not — expected statement is higher.
            self::ChequeUnpresented => ReconcilingItemDirection::Add,
            // The bank took money the book has not recorded — expected statement is lower.
            self::BankCharge => ReconcilingItemDirection::Subtract,
            self::InterestEarned => ReconcilingItemDirection::Add,
            // Runs either way; the accountant picks, the default is only a starting keystroke.
            self::FxAdjustment, self::BookError, self::Other => ReconcilingItemDirection::Subtract,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
