<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Contracts;

use App\Domain\Telematics\Data\PositionDto;

/**
 * R6's provider seam: one contract, one simulator, real adapters as hardware arrives.
 *
 * An adapter's whole job is **parsing**: turn a provider's payload into the fixed column set and
 * its own message id. It does not write, does not resolve tenancy and does not decide what a trip is;
 * `IngestPositions` owns the batch, the dedupe and the projection, so every provider gets the same
 * guarantees and a Phase 7 adapter cannot accidentally invent a second ingest path.
 *
 * `parse()` returns a list because providers batch: a device that went out of signal posts a backlog
 * of fixes in one request, and the design asks for one multi-row insert per webhook batch.
 */
interface GpsProvider
{
    /** The value stored in `gps_provider_accounts.adapter` and on every position row. */
    public function adapter(): string;

    /**
     * @param  array<string, mixed>  $payload  the provider's decoded request body
     * @return list<PositionDto>
     *
     * @throws \InvalidArgumentException when the payload is not a batch this adapter understands
     */
    public function parse(array $payload): array;
}
