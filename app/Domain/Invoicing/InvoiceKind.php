<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * How the invoice came to exist — which is a different question from what its lines charge for.
 *
 * `trip` is one job's delivered leg, `consolidated` is the monthly statement a customer actually wants
 * (R11: one invoice routinely bundles many trips), and `manual` is a clerk raising a document with no
 * chargeable behind it. It is not the lifecycle: a consolidated and a per-trip invoice both go
 * `draft → issued`, both allocate from the same gap-free `invoice` scope, and both post the same shape. The
 * kind exists so the screen can say which door it came from and so phase 4's "invoice per trip **and**
 * consolidated billing runs" is a testable distinction rather than a vibe.
 *
 * `opening` is the fourth door and it does not come from this system at all: it is last year's invoice,
 * still unpaid, arriving through the cutover intake. It is a real receivable — it ages, it is
 * owed, the customer will pay it — but it earned its revenue in the old books, so it carries no VAT and
 * credits owner capital rather than a revenue account. A register that called it `manual` would be
 * saying the office raised a charge today, which is exactly the lie the cutover tie-out exists to catch.
 */
enum InvoiceKind: string
{
    case Trip = 'trip';
    case Consolidated = 'consolidated';
    case Manual = 'manual';
    case Opening = 'opening';

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
            self::Opening => 'Opening balance',
        };
    }
}
