<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts;

/**
 * §14.1's alert taxonomy — the machine-readable form of §4.4's `alerts.type` CHECK list.
 *
 * The DB CHECK and this enum are deliberately the same eleven names, for the §20.7 reason: a trail
 * that accepts any string is a log file, and a register that accepts any type is a place where
 * "did the licence reminder ever fire" has no answer.
 *
 * `self_check_failure` is not in §4.4's list. It is here because §14.6's last line — "every one is
 * scheduled; every failure raises an alert" — needs a type, and §4.4's ten do not contain one that
 * fits: `posting_failure` is reserved by §11.3 for a posting that hit a locked period, and putting
 * digest drift or a numbering gap on the ledger exceptions screen hides it from the Owner, who is
 * the only person who can act on either.
 */
enum AlertType: string
{
    case DocExpiry = 'doc_expiry';
    case ServiceDue = 'service_due';
    case OverdueInvoice = 'overdue_invoice';
    case FuelVariance = 'fuel_variance';
    case Geofence = 'geofence';
    case RouteDeviation = 'route_deviation';
    case LoanDue = 'loan_due';
    case CashShortage = 'cash_shortage';
    case PermitExpiry = 'permit_expiry';
    case PostingFailure = 'posting_failure';
    case SelfCheckFailure = 'self_check_failure';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The severity a source gets when it has no reason to say otherwise.
     *
     * A source may still answer differently per candidate — an expiry fourteen days out is a warning
     * and one that has already passed is critical — so this is a default rather than a rule, and it
     * lives here so the eleven types do not each invent one at their call sites.
     */
    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::PostingFailure, self::SelfCheckFailure => AlertSeverity::Critical,
            self::DocExpiry, self::PermitExpiry, self::CashShortage => AlertSeverity::Warning,
            default => AlertSeverity::Warning,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DocExpiry => 'Document expiry',
            self::ServiceDue => 'Service due',
            self::OverdueInvoice => 'Overdue invoice',
            self::FuelVariance => 'Fuel variance',
            self::Geofence => 'Geofence',
            self::RouteDeviation => 'Route deviation',
            self::LoanDue => 'Loan instalment due',
            self::CashShortage => 'Cash shortage',
            self::PermitExpiry => 'Permit expiry',
            self::PostingFailure => 'Ledger posting failure',
            self::SelfCheckFailure => 'Scheduled self-check failure',
        };
    }

    /**
     * Whether a user may silence this type on a given channel.
     *
     * §14.1's exception is worded as "except for critical financial and safety alerts", which is a
     * property of the *alert* rather than of the type: the same `doc_expiry` type is a warning three
     * months out and a critical alert the day a truck crosses a border with an expired roadworthiness
     * certificate. So the gate asks the severity the row actually carries, and the type only
     * contributes the fact that it is a financial-or-safety family at all — which here is every type,
     * since none of the eleven is decorative.
     */
    public function respectsOptOut(AlertSeverity $severity): bool
    {
        return $severity !== AlertSeverity::Critical;
    }
}
