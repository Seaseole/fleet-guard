<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use InvalidArgumentException;

final class UnknownCurrency extends InvalidArgumentException
{
    public static function of(string $code): self
    {
        return new self(sprintf(
            'Currency "%s" is not in the currencies table. Adding a currency is a seeder row '
            .'(Production\CurrenciesSeeder); silently inventing one is how a foreign-currency '
            .'amount ends up stored with the wrong minor-unit count.',
            $code,
        ));
    }
}
