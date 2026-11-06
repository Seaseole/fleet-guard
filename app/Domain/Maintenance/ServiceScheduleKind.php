<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * How a service schedule measures its interval.
 *
 * `km` and `hours` are counters the truck accrues as it works; `date` is the calendar's; `condition`
 * is a person's judgement and is the only kind with no due point at all. The four are one enum rather
 * than four tables because the register lists them in one column and a dispatcher should not have to
 * know which module owns "due".
 */
enum ServiceScheduleKind: string
{
    case Km = 'km';
    case Hours = 'hours';
    case Date = 'date';
    case Condition = 'condition';

    public function label(): string
    {
        return match ($this) {
            self::Km => 'Distance',
            self::Hours => 'Engine hours',
            self::Date => 'Calendar',
            self::Condition => 'Condition',
        };
    }

    /** The unit the interval and the due point are stated in, for a form's suffix. */
    public function unit(): string
    {
        return match ($this) {
            self::Km => 'km',
            self::Hours => 'hours',
            self::Date => 'days',
            self::Condition => '—',
        };
    }

    public function isCounter(): bool
    {
        return $this === self::Km || $this === self::Hours;
    }
}
