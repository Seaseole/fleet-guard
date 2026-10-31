<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * How the invoice came to exist — which is a different question from what its lines charge for.
 *
 * `trip` is one job's delivered leg, `consolidated` is the monthly statement a customer actually wants
 * (§16 R11: one invoice routinely bundles many trips), and `manual` is a clerk raising a document with no
 * chargeable behind it. It is not the lifecycle: a consolidated and a per-trip invoice both go
 * `draft → issued`, both allocate from the same gap-free `invoice` scope, and both post the same shape. The
 * kind exists so the screen can say which door it came from and so §15 phase 4's "invoice per trip **and**
 * consolidated billing runs" is a testable distinction rather than a vibe.
 */
enum InvoiceKind: string
{
    case Trip = 'trip';
    case Consolidated = 'consolidated';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Trip => 'Per trip',
            self::Consolidated => 'Consolidated statement',
            self::Manual => 'Manual',
        };
    }
}
