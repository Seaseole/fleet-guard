<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

/**
 * §4.4 DOCUMENT.retention_class — why the paper is kept, not how long.
 *
 * The periods are deliberately absent from this enum. "Tax records: how many years" is an
 * accountant's answer with a statutory basis (§17's open list), and a number written here would
 * look like a rule while being a guess. What the enum does carry is the class, because the
 * retention report (§14.5) groups by it and any future purge needs a value it can refuse to act
 * on when the class was never set.
 */
enum RetentionClass: string
{
    case Tax = 'tax';
    case Legal = 'legal';
    case Operational = 'operational';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Tax => 'Tax record',
            self::Legal => 'Legal record',
            self::Operational => 'Operational',
            self::None => 'No retention',
        };
    }

    /**
     * Whether a purge job may ever delete the bytes for this class.
     *
     * `none` is the only class that says yes, and it says yes only because somebody chose it —
     * which is why the migration defaults an unclassified document to `operational` rather than to
     * `none`. The safe default is the one that keeps the file.
     */
    public function isPurgeable(): bool
    {
        return $this === self::None;
    }
}
