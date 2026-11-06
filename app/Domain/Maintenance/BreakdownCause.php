<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * BREAKDOWN `cause` — whose fault the roadside stop was, as far as anyone can tell.
 *
 * The cause is a claim about the world, not about the ledger: a `mechanical` breakdown is the fleet's
 * own wear, `road` and `load` are conditions the driver met, and `driver` is the only cause a company
 * can fairly charge back — which is why `recoverable_from_driver` may only be set on it, and the
 * migration's CHECK says so as well as {@see allowsDriverRecovery()}.
 *
 * `other` exists rather than making the column nullable: "we do not know" is an answer the register
 * must be able to count, and a null would silently leave the breakdown out of every cause tally.
 */
enum BreakdownCause: string
{
    case Mechanical = 'mechanical';
    case Driver = 'driver';
    case Road = 'road';
    case Load = 'load';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mechanical => 'Mechanical failure',
            self::Driver => 'Driver error',
            self::Road => 'Road condition',
            self::Load => 'Load or loading',
            self::Other => 'Other / unknown',
        };
    }

    /** Only a driver-caused stop can be recovered from the driver (the CHECK). */
    public function allowsDriverRecovery(): bool
    {
        return $this === self::Driver;
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $cause) {
            $options[$cause->value] = $cause->label();
        }

        return $options;
    }
}
