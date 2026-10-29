<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Operations\Actions\IssueTripAccessToken;

/**
 * §9.6 — what a driver's field capture may write.
 *
 * Four scopes because the kiosk surface is four screens, and a token is deliberately narrower than a
 * user: it opens the one thing a driver does on the road, not the account they do not have (ADR-0006).
 *
 * `fuel` and `expense` are declared now, before the Fuel module exists in Phase 3, so the scope list a
 * dispatcher picks on a token does not change shape under them mid-corridor. A scope whose Action does
 * not exist yet refuses rather than pretending: see {@see IssueTripAccessToken}.
 */
enum TripAccessScope: string
{
    case Pod = 'pod';
    case Odometer = 'odometer';
    case Fuel = 'fuel';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Pod => 'proof of delivery',
            self::Odometer => 'odometer and engine hours',
            self::Fuel => 'fuel issues',
            self::Expense => 'trip expenses',
        };
    }

    /**
     * The scopes this build can actually honour.
     *
     * Phase 2 ships the POD screen; odometer capture arrives with the telematics slice, and fuel and
     * expense write through the Fuel module's Actions, which arrive in Phase 3. Naming them here rather
     * than accepting them everywhere means the kiosk says "not built yet" at issue time — when a
     * dispatcher can still do something about the token — instead of a driver standing at a border post
     * with a form that cannot save.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::Pod];
    }

    /** @param list<string> $values */
    public static function fromList(array $values): array
    {
        $scopes = [];

        foreach ($values as $value) {
            $scope = $value instanceof self ? $value : self::tryFrom((string) $value);

            if ($scope === null) {
                throw new \InvalidArgumentException('Unknown kiosk scope: '.((string) $value));
            }

            $scopes[$scope->value] = $scope;
        }

        return array_values($scopes);
    }

    /** @param list<self> $scopes */
    public static function toCsv(array $scopes): string
    {
        return implode(',', array_map(static fn (self $scope): string => $scope->value, $scopes));
    }

    public static function fromCsv(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        return self::fromList(array_map('trim', explode(',', $csv)));
    }
}
