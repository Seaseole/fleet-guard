<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What a driver's field capture may write.
 *
 * Four scopes because the kiosk surface is four screens, and a token is deliberately narrower than a
 * user: it opens the one thing a driver does on the road, not the account they do not have (ADR-0006).
 *
 * The vocabulary has been stable since Phase 2; which of the four a build can honour changes as its
 * Actions land, and {@see self::implemented()} is the single answer. A scope that is named but not
 * built refuses at issue time rather than promising a form that cannot save.
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
     * Phase 2 shipped the POD screen and slice 3g adds the driver's fuel screen, which writes through
     * `RecordKioskFuelIssue`. Odometer capture still arrives with the telematics slice, and the trip
     * expense screen is unbuilt; naming those here rather than accepting them everywhere means the
     * kiosk says "not built yet" at issue time — when a dispatcher can still do something about the
     * token — instead of a driver standing at a border post with a form that cannot save.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::Pod, self::Fuel];
    }

    /**
     * @param  list<TripAccessScope|string>  $values
     * @return list<self>
     */
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

    /** @return list<self> */
    public static function fromCsv(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        return self::fromList(array_map('trim', explode(',', $csv)));
    }
}
