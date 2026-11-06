<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * TYRE `status`, minus the spec's `rotating`.
 *
 * Rotation is an event — a tyre is taken off one position and fitted to another — and the
 * `tyre_positions` history records it twice (a removal and a fitment) with dates and odometers. A
 * `rotating` status would be a fifth state nothing can enter or leave through an Action, so the four
 * here are the states a tyre actually rests in: spare, on a truck, waiting for a retread, or finished.
 *
 * `retread_due` is a verdict a person reaches, not a derived one like a service schedule's: whether a
 * casing is worth retreading depends on the casings inspector, not only on tread depth.
 */
enum TyreStatus: string
{
    case OnSpares = 'on_spares';
    case InService = 'in_service';
    case RetreadDue = 'retread_due';
    case Scrap = 'scrap';

    public function label(): string
    {
        return match ($this) {
            self::OnSpares => 'On spares',
            self::InService => 'In service',
            self::RetreadDue => 'Retread due',
            self::Scrap => 'Scrapped',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::OnSpares => 'neutral',
            self::InService => 'success',
            self::RetreadDue => 'warning',
            self::Scrap => 'danger',
        };
    }

    /** Only a tyre on the spares rack may be fitted; a worn or scrapped casing stays off the road. */
    public function isFitForService(): bool
    {
        return $this === self::OnSpares;
    }
}
