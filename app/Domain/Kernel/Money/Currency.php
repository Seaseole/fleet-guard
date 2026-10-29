<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0004 #8: a currency is data, not an enum. `minor_units` is the authority for scale —
 * deliberately not read from brick/money's ISO table, because this corridor set includes
 * codes ISO does not define (ZWG) and a currency's decimal count should be a row an
 * accountant can see, not a library's opinion.
 *
 * Reference data shared by every company, so it is the one Kernel model with no company scope.
 */
final class Currency extends Model
{
    /** @var array<string, self> */
    private static array $cache = [];

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'minor_units', 'rounding_mode', 'symbol', 'is_active'];

    protected function casts(): array
    {
        return [
            'minor_units' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Memoised: this is resolved on almost every money write, and a lookup miss is a bug in
     * the seeder rather than something a caller should be expected to handle.
     */
    public static function of(string $code): self
    {
        $code = self::normalise($code);

        if (! isset(self::$cache[$code])) {
            $found = self::query()->find($code);

            if ($found === null) {
                throw UnknownCurrency::of($code);
            }

            self::$cache[$code] = $found;
        }

        return self::$cache[$code];
    }

    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return array<string, self> */
    public static function active(): array
    {
        return self::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->keyBy('code')
            ->all();
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * The column is `unsignedTinyInteger`, so a negative scale is not representable — which is
     * exactly what brick's CustomContext and BigDecimal::toScale() require.
     *
     * @return int<0, max>
     */
    public function minorUnits(): int
    {
        return max(0, $this->minor_units);
    }

    public function isSameAs(string $code): bool
    {
        return $this->code === self::normalise($code);
    }
}
