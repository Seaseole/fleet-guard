<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * INSPECTION `kind` — when the check happens, and therefore what it looks at.
 *
 * The checklist is the enum's, not the form's: a pre-trip walk-around, a periodic workshop check and
 * a tachograph check ask different questions, and a register whose item list lived in a Blade file
 * would change meaning every time the form was edited. Keys are stored in the JSON, so a label can be
 * reworded without orphaning yesterday's answers; a removed key is a migration to this enum and the
 * old rows keep an answer nothing now asks, which is what a history is.
 */
enum InspectionKind: string
{
    case PreTrip = 'pre_trip';
    case Periodic = 'periodic';
    case Tacho = 'tacho';

    public function label(): string
    {
        return match ($this) {
            self::PreTrip => 'Pre-trip walk-around',
            self::Periodic => 'Periodic inspection',
            self::Tacho => 'Tachograph check',
        };
    }

    /** @return array<string, string> item key => question, in the order the form prints them. */
    public function checklist(): array
    {
        return match ($this) {
            self::PreTrip => [
                'brakes' => 'Brakes and handbrake',
                'steering' => 'Steering and play in the wheel',
                'tyres' => 'Tyres, tread and wheel nuts',
                'lights' => 'Lights and indicators',
                'fluids' => 'Oil, coolant, leaks',
                'coupling' => 'Coupling and fifth wheel',
                'load' => 'Load secured and within limits',
                'cab' => 'Mirrors, wipers, horn',
            ],
            self::Periodic => [
                'brakes' => 'Brakes, lines and air system',
                'steering' => 'Steering, kingpins and linkage',
                'tyres' => 'Tyres and wheel alignment',
                'suspension' => 'Springs, shackles and shock absorbers',
                'lights' => 'Lighting and reflectors',
                'exhaust' => 'Exhaust, smoke and emissions',
                'chassis' => 'Chassis, cab mounts and rust',
                'safety' => 'Extinguisher, triangles and first aid',
            ],
            self::Tacho => [
                'tachograph' => 'Tachograph records and warnings',
                'seal' => 'Seal intact and unbroken',
                'calibration' => 'Calibration certificate current',
                'hours' => 'Driver hours within limits',
            ],
        };
    }

    /** @return list<string> */
    public function checklistKeys(): array
    {
        return array_keys($this->checklist());
    }
}
