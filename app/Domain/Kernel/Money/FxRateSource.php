<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

enum FxRateSource: string
{
    case Manual = 'manual';
    case RbgDaily = 'rbg_daily';
    case Bpb = 'bpb';
    case Contract = 'contract';
    case CustomerAgreed = 'customer_agreed';
    case BankStatement = 'bank_statement';
    case OpeningBalance = 'opening_balance';

    /** ADR-0004 #6: set when the base amount was edited and the rate was back-solved. */
    case ManualOverride = 'manual_override';

    /**
     * Which sources may be trusted to pre-fill a new document. Manual and override are
     * decisions, not feeds, so they never pre-fill — they are only what a user chose.
     */
    public function isFeed(): bool
    {
        return $this === self::RbgDaily || $this === self::Bpb || $this === self::BankStatement;
    }
}
