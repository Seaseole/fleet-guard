<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One five-a-minute budget for each of the two confirmation doors Fortify leaves unthrottled: the ⚑
 * password check (the gate that stands between a stolen logged-in laptop and a journal posting)
 * and the six-digit code that turns on a second factor. Both are short secrets checked by comparison, and
 * the enrolment code is the only one in the application with a one-in-a-million guess — it was the only
 * such gate with no limiter at all, while the TOTP *login* challenge next to it has always had five.
 *
 * **Counted on failure, not on request.** A person fumbling with a clock-drifted authenticator is doing
 * what an attacker is doing, one code at a time, and a budget that charges them both punishes the honest
 * case for the sake of the dishonest one. This is Fortify's own `LoginRateLimiter` shape. The numbers are
 * constants rather than config: a brute-force budget is not a business rule an Owner can answer.
 */
final class ConfirmationThrottle
{
    public const REAUTH = 'reauth';

    public const TWO_FACTOR_CONFIRM = 'two-factor-confirm';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public static function keyFor(string $door, ?int $userId, ?string $ip): string
    {
        return $door.'|'.($userId ?? 'guest').'|'.$ip;
    }

    /**
     * Run one attempt, charging the budget only when it fails.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $attempt
     * @return TReturn
     */
    public static function attempt(string $door, string $field, ?int $userId, ?string $ip, Closure $attempt): mixed
    {
        $key = self::keyFor($door, $userId, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => self::refusedMessage($key),
            ]);
        }

        try {
            $result = $attempt();
        } catch (Throwable $exception) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw $exception;
        }

        RateLimiter::clear($key);

        return $result;
    }

    public static function refusedMessage(string $key): string
    {
        return __('Too many incorrect attempts. Try again in :seconds seconds.', [
            'seconds' => RateLimiter::availableIn($key),
        ]);
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public static function decaySeconds(): int
    {
        return self::DECAY_SECONDS;
    }
}
