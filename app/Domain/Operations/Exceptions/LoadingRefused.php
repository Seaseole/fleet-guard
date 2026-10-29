<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exceptions;

use RuntimeException;

/**
 * §10.2's `assigned → loaded` refusals.
 *
 * Each message carries the number a dispatcher acts on — the limit, the total, and which load pushed
 * it over — because "overloaded" without the arithmetic is a message that gets argued with at the
 * loading bay.
 */
final class LoadingRefused extends RuntimeException
{
    /**
     * Every finding the gate raised, as one refusal.
     *
     * Joined whole rather than first-only: the loading bay re-runs the check after each fix, and a
     * message that reveals the second problem only once the first is cleared gets worked around.
     *
     * @param  list<string>  $findings
     */
    public static function gate(array $findings): self
    {
        return new self('This trip cannot be marked loaded: '.implode(' ', $findings));
    }

    public static function noLoads(string $tripNo): self
    {
        return new self(sprintf(
            'Trip %s carries no loads. A truck that leaves empty is dispatched from `assigned`, not marked loaded (§10.2).',
            $tripNo,
        ));
    }

    public static function capacityUnknown(string $kind, string $label): self
    {
        return new self(sprintf(
            '%s %s has no capacity recorded, so its load cannot be checked. Enter the payload in the %s register first — an unmeasured limit is not a limit.',
            ucfirst($kind),
            $label,
            $kind,
        ));
    }

    public static function overloaded(string $kind, string $label, float $tonnes, float $capacity, string $heaviest): self
    {
        return new self(sprintf(
            '%s %s carries %s t against a %s t limit — %s t over. The heaviest single load is "%s"; split it or change the equipment.',
            ucfirst($kind),
            $label,
            self::tonnage($tonnes),
            self::tonnage($capacity),
            self::tonnage($tonnes - $capacity),
            $heaviest,
        ));
    }

    public static function missingWaybill(string $commodity, float $tonnes): self
    {
        return new self(sprintf(
            'The load "%s" (%s t) has no waybill document attached. §10.2 requires the paper before a trip is marked loaded — this is what a POD is later reconciled against.',
            $commodity,
            self::tonnage($tonnes),
        ));
    }

    public static function waybillVoided(string $commodity, string $title): self
    {
        return new self(sprintf(
            'The waybill named for the load "%s" ("%s") is voided. Voided paper is evidence of a decision, not authority to move freight (§10.8).',
            $commodity,
            $title,
        ));
    }

    private static function tonnage(float $tonnes): string
    {
        return rtrim(rtrim(number_format($tonnes, 2, '.', ''), '0'), '.');
    }
}
