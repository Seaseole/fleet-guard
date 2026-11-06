<?php

declare(strict_types=1);

namespace App\Domain\Operations\Kiosk;

use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\TripAccessScope;

/**
 * A driver's link, proved against one token, one scope and one PIN.
 *
 * The comparisons live here rather than in each screen's Action so there is one readable answer to
 * "what does a driver's link prove", and the constructor is private so an authority cannot be minted
 * without passing them. The object is evidence, not a flag — a subordinate Action that receives one
 * has nothing left to verify, and one that receives none was written by a person and asks the seat.
 *
 * The two hashes are compared in constant time, because a PIN is the check an attacker gets to
 * repeat, and the refusal never says which half failed.
 */
final readonly class KioskAuthority
{
    private function __construct(
        public TripAccessToken $token,
        public TripAccessScope $scope,
    ) {}

    public static function verified(TripAccessToken $token, string $secret, string $pin, TripAccessScope $scope): self
    {
        if (! $token->isLive()) {
            throw new \InvalidArgumentException('That driver link is closed.');
        }

        if (! $token->hasScope($scope)) {
            throw new \InvalidArgumentException(sprintf(
                'This link does not carry the %s scope.',
                $scope->label(),
            ));
        }

        if (! hash_equals($token->token_hash, hash('sha256', $secret)) || ! $token->hasPinFor($pin, $secret)) {
            throw new \InvalidArgumentException('That PIN does not match this trip.');
        }

        return new self($token, $scope);
    }

    public function tokenId(): string
    {
        return $this->token->id;
    }

    /**
     * A token is bound to one trip; an Action that writes on another one is not holding the door it
     * thinks it is, even if the secret and PIN are real.
     */
    public function actsOn(string $tripId): bool
    {
        return $this->token->trip_id === $tripId;
    }
}
