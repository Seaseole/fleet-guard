<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

/**
 * DOCUMENT_VERSION.virus_scan_status.
 *
 * `pending` is a state and not a failure: Q8 is still open on what the scanner is on Laravel
 * Cloud (no local daemon), so the honest value at write time is "nothing has judged these bytes
 * yet". What makes that safe rather than decorative is
 * {@see ScanVerdict::isServable()} — the download gate reads this verdict, so an unscanned file is
 * a file that does not leave the disk in production.
 */
enum ScanVerdict: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not scanned',
            self::Clean => 'Clean',
            self::Infected => 'Infected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Clean => 'success',
            self::Infected => 'danger',
        };
    }

    /**
     * Whether bytes with this verdict may leave the private disk.
     *
     * Asymmetric on purpose. With a scanner configured, `pending` means "the queue has not got
     * there yet" and the download waits. With no scanner at all — the local and the Cloud-without-
     * sidecar case — refusing every download would make the application unusable, so the design
     * substitutes the strict type allowlist and says so in the scan detail. That is a documented
     * gap, not a silent one, and it is the option Q8 leaves open.
     *
     * `infected` never serves, in any environment.
     */
    public function isServable(bool $scannerConfigured): bool
    {
        return match ($this) {
            self::Clean => true,
            self::Infected => false,
            self::Pending => ! $scannerConfigured,
        };
    }
}
