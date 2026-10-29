<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Exceptions\KioskScopeUnavailable;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\TripAccessScope;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * hand a driver a door into exactly one trip.
 *
 * The plain secret and the PIN leave this Action **once**, in the return value, and are never stored: the
 * row keeps `hash('sha256', …)` for both. That is the whole design — the dispatcher reads the URL off the
 * screen, the driver's phone holds it, and nothing in the database can be queried back into a working
 * token, so a leaked read of `trip_access_tokens` is a leak of hashes.
 *
 * Three rules are enforced here rather than in the screen, because a screen changes and a token does not:
 *
 * 1. **the driver must be the trip's driver**. A token that names someone else is how
 * `actor_kind = kiosk` attribution becomes fiction, and how a second truck's POD lands on the wrong
 *    trip;
 * 2. **every scope asked for must be one this build honours** ({@see TripAccessScope::implemented()}).
 *    Handing out a `fuel` scope that no Action reads would tell the driver the phone is broken at the
 *    pump;
 * 3. **the horizon is the trip's**, not the clock's — planned end plus `fleet.kiosk.ttl_hours_after_trip_end`.
 */
final class IssueTripAccessToken
{
    public function __construct(
        private readonly DriverDirectory $drivers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<TripAccessScope|string>  $scopes
     * @return array{token: TripAccessToken, secret: string, pin: string, url: string}
     */
    public function __invoke(Trip $trip, int $driverId, array $scopes, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($trip, $driverId, $scopes, $actorId): array {
            $wanted = TripAccessScope::fromList($scopes);

            if ($wanted === []) {
                throw new KioskScopeUnavailable('A token with no scopes opens nothing. Pick at least one thing the driver may capture.');
            }

            $unbuilt = array_values(array_filter(
                $wanted,
                static fn (TripAccessScope $scope): bool => ! in_array($scope, TripAccessScope::implemented(), true),
            ));

            if ($unbuilt !== []) {
                throw new KioskScopeUnavailable(sprintf(
                    '%s is not captured through the kiosk yet (puts it in a later phase), so issuing it would promise the driver a form that cannot save.',
                    implode(', ', array_map(static fn (TripAccessScope $s): string => $s->label(), $unbuilt)),
                ));
            }

            if ((int) $trip->driver_id !== $driverId) {
                throw new \InvalidArgumentException(sprintf(
                    'Trip %s is crewed by driver #%s; a token for somebody else would attribute the capture to the wrong person.',
                    $trip->trip_no,
                    (string) $trip->driver_id,
                ));
            }

            $driver = $this->drivers->find($driverId);

            if ($driver === null) {
                throw new \InvalidArgumentException('That driver is not in this company, so no trip of theirs can be captured here.');
            }

            if (! $driver->kioskEnabled) {
                throw new \InvalidArgumentException(sprintf(
                    '%s is not enabled for field capture. Turn it on against the driver record first — the flag exists so a departed driver cannot be handed a live door by accident.',
                    $driver->name,
                ));
            }

            $secret = bin2hex(random_bytes(24));
            $pin = (string) random_int(100_000, 999_999);

            $token = TripAccessToken::create([
                'company_id' => CurrentCompany::id(),
                'trip_id' => $trip->id,
                'driver_id' => $driverId,
                'token_hash' => hash('sha256', $secret),
                'pin_hash' => hash('sha256', $pin.'|'.$secret),
                'scopes' => TripAccessScope::toCsv($wanted),
                'expires_at' => $this->horizon($trip),
                'issued_by' => $actorId,
            ]);

            // §14.4 asks this trail the question "who could have written that POD at 03:12?", and the
            // answer is the issuance, not the capture: the token never leaves as a hash.
            $this->audit->security(SecurityEventKind::KioskTokenIssued, [
                'trip_access_token_id' => $token->id,
                'trip_id' => $trip->id,
                'driver_id' => $driverId,
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ], $actorId);

            return [
                'token' => $token,
                'secret' => $secret,
                'pin' => $pin,
                // The company is in the path because a driver request has no session to resolve one from
                // (guard is fail-closed), so the link has to name it.
                'url' => route('kiosk.capture', ['company' => CurrentCompany::id(), 'secret' => $secret]),
            ];
        });
    }

    /**
     * Planned end plus the configured grace, in the company's clock.
     *
     * A trip that has no planned end at all — a draft nobody has dated — gets the horizon from its job's
     * delivery promise, because a token that dies before the truck has even left is not a token.
     */
    private function horizon(Trip $trip): Carbon
    {
        $ttl = (int) config('fleet.kiosk.ttl_hours_after_trip_end', 24);

        $end = $trip->arrived_at
            ?? $trip->completed_at
            ?? $trip->job?->required_delivery_on?->endOfDay()
            ?? $trip->planned_departure_at
            ?? CurrentCompany::model()->created_at;

        return Carbon::parse($end->toDateTimeString(), $end->timezone)->addHours($ttl);
    }
}
