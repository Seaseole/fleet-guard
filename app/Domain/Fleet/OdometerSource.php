<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * Where an odometer reading came from (§4.3). The source is what makes a disputed number
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

    public function label(): string
    {
        return match ($this) {
            self::TripDeparture => 'Trip departure',
            self::TripArrival => 'Trip arrival',
            self::FuelIssue => 'Fuel issue',
            self::Manual => 'Manual entry',
            self::Kiosk => 'Driver kiosk',
        };
    }
}
