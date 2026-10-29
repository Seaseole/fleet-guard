<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Guards an `unsignedBigInteger` minor-unit column (ADR-0004 #1, D6).
 *
 * It exists so the column can never silently receive `1250.00` from a screen that was thinking
 * in major units. The database type already prevents decimals; what this prevents is a float
 * arriving from PHP and being cast to int by the driver on the way in.
 *
 * TSet is `mixed` rather than the narrow type the cast actually wants, because the guards below
 * are the point: a caller passing a float or a decimal string is exactly what this catches, and
 * declaring the input as `int|Money` would tell the analyser those branches are unreachable.
 *
 * @implements CastsAttributes<int, mixed>
 */
final class MinorUnits implements CastsAttributes
{
    /** @return int */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return 0;
        }

        if (! is_numeric($value)) {
            throw new RuntimeException("Column {$key} on ".class_basename($model).' holds a non-numeric minor amount.');
        }

        return (int) $value;
    }

    /** @return int */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value instanceof Money) {
            return $value->minorUnits;
        }

        if (is_float($value)) {
            throw new RuntimeException(sprintf(
                '%s::$%s must be integer minor units; got float %s.',
                class_basename($model),
                $key,
                var_export($value, true),
            ));
        }

        if (is_string($value) && ! preg_match('/^-?\d+$/', $value)) {
            throw new RuntimeException(sprintf(
                '%s::$%s must be integer minor units; got "%s".',
                class_basename($model),
                $key,
                $value,
            ));
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException(sprintf('%s::$%s cannot hold a %s.', class_basename($model), $key, get_debug_type($value)));
        }

        return (int) $value;
    }
}
