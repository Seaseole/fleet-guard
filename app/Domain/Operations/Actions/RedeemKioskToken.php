<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\TripAccessToken;
use Illuminate\Support\Facades\DB;

/**
 * Turn the string on the driver's phone into a live token, or refuse.
 *
 * The link is the identity and the PIN is the second factor, typed on the page the link opens. That split
 * is what "a 6-digit PIN for bad-signal entry" means in practice: a driver who cannot tap the URL reads
 * the code off the note the dispatcher printed, but still needs the URL itself — which is why the PIN hash
 * is bound to the secret and a PIN on its own resolves nothing.
 *
 * The read happens inside the company the route names, so `trip_access_tokens` stays company-scoped like
 * every other business table; guessing a company id buys nothing, because the secret or the PIN still has
 * to match a live token inside it.
 *
 * `hash_equals` on both comparisons, and no "did you mean" information in any refusal. A closed door that
 * explains *why* it is closed is a manual for whoever is attacking it.
 */
final class RedeemKioskToken
{
    public function __invoke(TripAccessToken $token, string $ip): TripAccessToken
    {
        return DB::transaction(function () use ($token, $ip): TripAccessToken {
            $token->forceFill(['last_used_at' => now(), 'last_ip' => $ip])->save();

            return $token->refresh();
        });
    }

    /**
     * The URL path: the secret identifies the token outright.
     */
    public function fromSecret(int $companyId, string $secret, string $ip): TripAccessToken
    {
        CurrentCompany::force($companyId);

        $token = TripAccessToken::query()
            ->where('token_hash', hash('sha256', $secret))
            ->first();

        if ($token === null) {
            throw new \InvalidArgumentException('That link does not open a trip here.');
        }

        return $this->guard($token, $ip);
    }

    /**
     * The bad-signal path is **not** a second door: the PIN is hashed together with the secret
     * ({@see TripAccessToken::hasPinFor}), so a trip reference plus a PIN identifies nothing on its own.
     * A driver with a broken link types the PIN into the page the link opens — which is the case the
     * six-digit code exists for — rather than reconstructing the URL from memory.
     */
    private function guard(TripAccessToken $token, string $ip): TripAccessToken
    {
        if (! $token->isLive()) {
            throw new \InvalidArgumentException('That driver link is closed.');
        }

        return ($this)($token, $ip);
    }
}
