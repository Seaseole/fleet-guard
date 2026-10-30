<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Adapters;

use App\Domain\Telematics\Contracts\GpsProvider;
use App\Domain\Telematics\Data\PositionDto;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * §16 R6's first adapter — and the only one Phase 2 needs.
 *
 * It parses the payload `gps:simulate` writes, which is deliberately shaped like a real provider's
 * webhook: a batch of messages, an opaque id per message, a truck identifier and a fix. The simulator
 * therefore exercises the same path a Teltonika gateway will: parse → validate → batch insert →
 * projection. Phase 7 adds adapters; none of them has to touch the ingest Action.
 *
 * Shape errors throw and refuse the whole batch (the provider sent something this adapter does not
 * understand, and a retry after the fix is safe because of the message-id guard). A coordinate that
 * parses but is not on the planet is *semantic* and is rejected per message by `IngestPositions`,
 * so one bad row in a backlog of 200 does not throw away the other 199.
 */
final class SimulatorProvider implements GpsProvider
{
    public function adapter(): string
    {
        return 'simulator';
    }

    public function parse(array $payload): array
    {
        $messages = $payload['messages'] ?? null;

        if (! is_array($messages) || $messages === []) {
            throw new InvalidArgumentException('A simulator batch carries a non-empty "messages" array (§14.3 batched ingest).');
        }

        $positions = [];

        foreach (array_values($messages) as $index => $message) {
            if (! is_array($message)) {
                throw new InvalidArgumentException(sprintf('Simulator message %d is not an object.', $index + 1));
            }

            $positions[] = $this->position($message, $index + 1);
        }

        return $positions;
    }

    /** @param array<string, mixed> $message */
    private function position(array $message, int $number): PositionDto
    {
        $id = $message['id'] ?? null;
        $truckId = $message['truck_id'] ?? null;
        $fixedAt = $message['fixed_at'] ?? null;
        $lat = $message['lat'] ?? null;
        $lng = $message['lng'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException(sprintf('Simulator message %d has no id — the retry guard needs one (§14.3).', $number));
        }

        if (! is_numeric($truckId) || (int) $truckId <= 0) {
            throw new InvalidArgumentException(sprintf('Simulator message %d does not say which truck it is from.', $number));
        }

        if (! is_string($fixedAt) || trim($fixedAt) === '') {
            throw new InvalidArgumentException(sprintf('Simulator message %d has no fixed_at.', $number));
        }

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw new InvalidArgumentException(sprintf('Simulator message %d has no usable coordinates.', $number));
        }

        try {
            $moment = CarbonImmutable::parse($fixedAt);
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf('Simulator message %d has an unreadable fixed_at "%s".', $number, $fixedAt));
        }

        $tripId = $message['trip_id'] ?? null;

        return new PositionDto(
            provider: $this->adapter(),
            providerMessageId: trim($id),
            truckId: (int) $truckId,
            tripId: is_string($tripId) && trim($tripId) !== '' ? trim($tripId) : null,
            fixedAt: $moment,
            lat: (float) $lat,
            lng: (float) $lng,
            speedKph: $this->whole($message['speed_kph'] ?? null),
            headingDeg: $this->whole($message['heading_deg'] ?? null),
            odometerKm: $this->whole($message['odometer_km'] ?? null),
            accuracyM: $this->whole($message['accuracy_m'] ?? null),
            raw: $message,
        );
    }

    private function whole(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
