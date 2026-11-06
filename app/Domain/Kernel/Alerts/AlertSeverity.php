<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts;

/**
 * The `alerts.severity` value list.
 *
 * Severity is what decides delivery, not display: The "critical financial and safety alerts"
 * cannot be opted out of, and the only honest reading of that is the severity the alert carried when
 * it fired. A screen can render it with `tone()`; a user cannot mute it.
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    /** The `ui/badge` tone for this severity; the caller supplies the icon. */
    public function tone(): string
    {
        return match ($this) {
            self::Info => 'neutral',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    /**
     * Whether this severity outranks a user's opt-out.
     *
     * Named as a question about the rule rather than as `isCritical()` so the call site says which
     * promise it is enforcing.
     */
    public function cannotBeOptedOut(): bool
    {
        return $this === self::Critical;
    }

    /** Ordering, so a register can sort by what matters without a CASE expression per screen. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
