<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/** LOAN_INSTALLMENT.status — the plan row's own machine, one step below the loan's. */
enum InstallmentStatus: string
{
    case Scheduled = 'scheduled';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Restructured = 'restructured';

    /** Rows the forecast and the arrears list still count: the plan the lender would enforce. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Scheduled, self::Partial, self::Overdue], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
