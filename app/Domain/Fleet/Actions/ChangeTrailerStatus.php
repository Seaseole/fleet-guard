<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Trailer;
use Illuminate\Support\Facades\DB;

/**
 * The only path a trailer's status moves through (§10.1, D1); disposal clears `active` with it.
 */
final class ChangeTrailerStatus
{
    public function __invoke(Trailer $trailer, FleetStatus $to, ?string $reason = null): Trailer
    {
        return DB::transaction(function () use ($trailer, $to, $reason): Trailer {
            $trailer->moveTo($to, $reason);

            if ($to === FleetStatus::Sold && $trailer->active) {
                $trailer->forceFill(['active' => false])->save();
            }

            return $trailer->refresh();
        });
    }
}
