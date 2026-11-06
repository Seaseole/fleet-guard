<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use Illuminate\Support\Facades\DB;

/**
 * The only path a driver's status moves through (D1).
 *
 * The trait writes the `operational_events` row before the column changes; this Action only wraps
 * the pair in a transaction and hands the reason through. A missing reason on a move into
 * `suspended` or `left` throws {@see IllegalTransition} with a sentence
 * the screen can show as-is.
 */
final class ChangeDriverStatus
{
    public function __invoke(Driver $driver, DriverStatus $to, ?string $reason = null): Driver
    {
        return DB::transaction(function () use ($driver, $to, $reason): Driver {
            $driver->moveTo($to, $reason);

            return $driver->refresh();
        });
    }
}
