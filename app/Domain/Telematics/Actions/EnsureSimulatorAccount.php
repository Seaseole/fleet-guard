<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Actions;

use App\Domain\Telematics\Models\GpsProviderAccount;

/**
 * The simulator's feed row, made once (GPS_PROVIDER_ACCOUNT).
 *
 * `gps:simulate` cannot write the account itself — D1 keeps writes out of the console — and it must
 * not fail on a fresh database either, because the simulator is the only way to see the map before
 * hardware arrives. So the door is an Action that returns the existing row or makes it.
 *
 * A real provider account is not this Action: it carries a credential and an endpoint, and Phase 7
 * gives it a screen behind its own permission. What the simulator needs is a row that says "this
 * company ingests through the simulator adapter", and that is idempotent by `(company, adapter)`.
 */
final class EnsureSimulatorAccount
{
    public function __invoke(?string $endpoint = null): GpsProviderAccount
    {
        return GpsProviderAccount::query()->firstOrCreate(
            ['adapter' => 'simulator'],
            ['endpoint' => $endpoint, 'active' => true],
        );
    }
}
