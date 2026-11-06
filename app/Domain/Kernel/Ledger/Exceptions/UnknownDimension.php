<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Exceptions;

use App\Domain\Kernel\Ledger\Data\Dimension;
use InvalidArgumentException;

final class UnknownDimension extends InvalidArgumentException
{
    public static function column(string $column): self
    {
        return new self(sprintf(
            'No ledger dimension maps to the column "%s". The typed columns are: %s.',
            $column,
            implode(', ', Dimension::columns()),
        ));
    }

    /** A posting carried a dimension key that has no column in. */
    public static function key(string $key): self
    {
        return new self(
            'PostingDto line carries the dimension "'.$key.'", which is not a typed column in. '
            .'Long-tail dimensions (permit_id, tyre_id, route_id) go in dimension_extra; a dimension '
            .'earns a column when a report needs it twice.'
        );
    }
}
