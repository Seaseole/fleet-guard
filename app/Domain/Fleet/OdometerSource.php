<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * Where an odometer reading came from. The source is what makes a disputed number
 * explainable six months later: a `kiosk` entry and a `manual` office correction do not deserve the
 * same trust, and the register says which one a row is.
 */
enum OdometerSource: string
{
    case TripDeparture = 'trip_departure';
    case TripArrival = 'trip_arrival';
    case FuelIssue = 'fuel_issue';
    case Manual = 'manual';
    case Kiosk = 'kiosk';
    case Cutover = 'cutover';

    public function label(): string
    {
        return match ($this) {
            self::TripDeparture => 'Trip departure',
            self::TripArrival => 'Trip arrival',
            self::FuelIssue => 'Fuel issue',
            self::Manual => 'Manual entry',
            self::Kiosk => 'Driver kiosk',
            // What the truck's face read on cutover day. A separate source because a
            // reading that did not come from this system's own doors does not deserve the same
            // trust as one that did — and because "when did the fleet start knowing this number"
            // has to have an answer other than "someone typed it".
            self::Cutover => 'Cutover intake',
        };
    }
}
