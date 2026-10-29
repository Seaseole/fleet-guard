<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

/**
 * The five named field gates of §9.4.
 *
 * A gate is not a permission: the permission says which capability a role holds, and the gate is
 * the single name a screen asks before it renders a column. Keeping them separate is what makes
 * the rule auditable — "can this person see the trip margin?" has one answer, and `fleet:gates
 * explain` prints it for every role without reading a Blade file.
 *
 * Each case's value is the ability name used by `@can()`/`Gate::allows()`, and the case name is
 * the method on {@see FieldGatePolicy}. They are kept identical on purpose: a gate that can be
 * grepped for by name is a gate that can be found in review.
 */
enum FieldGate: string
{
    case SeeTripMargin = 'seeTripMargin';
    case SeeVehicleCost = 'seeVehicleCost';
    case SeePayroll = 'seePayroll';
    case SeeBankDetail = 'seeBankDetail';
    case SeeCustomerTerms = 'seeCustomerTerms';

    /** The capability that opens this gate (§9.3's "hidden" cells are exactly the gates a role lacks). */
    public function permission(): string
    {
        return match ($this) {
            self::SeeTripMargin => 'trips.margin.view',
            self::SeeVehicleCost => 'fleet.cost.view',
            self::SeePayroll => 'payroll.view',
            self::SeeBankDetail => 'bank.number.view',
            self::SeeCustomerTerms => 'customer.terms.view',
        };
    }

    /** What the field shows when open, used in the tooltip on the locked placeholder. */
    public function subject(): string
    {
        return match ($this) {
            self::SeeTripMargin => 'trip contribution and margin',
            self::SeeVehicleCost => 'vehicle purchase price, finance and book value',
            self::SeePayroll => 'salaries, advances and settlements',
            self::SeeBankDetail => 'the full bank account number',
            self::SeeCustomerTerms => 'customer credit terms and agreed rates',
        };
    }

    /**
     * The masked form shown when the gate is closed. §9.4 hides the field rather than the row, so
     * a Dispatcher still sees that a trip made money — only not how much.
     */
    public function placeholder(): string
    {
        return '—';
    }

    /** @return list<string> */
    public static function abilityNames(): array
    {
        return array_map(fn (self $gate) => $gate->value, self::cases());
    }
}
