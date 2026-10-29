<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Operations\Models\TripAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * §9.6's `revoked` flag, flipped by the seat that issued it.
 *
 * Revocation, not deletion: the row is the audit's record of who held a door to which trip and when, and
 * a deleted token would turn "who could have written this POD at 03:12?" into an unanswerable question.
 *
 * `expires_at` is left alone. Two reasons a link stops working, kept in two columns, so a dispatcher
 * asking "why is the driver's link dead" gets one answer instead of a merged guess.
 */
final class RevokeTripAccessToken
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function __invoke(TripAccessToken $token): TripAccessToken
    {
        return DB::transaction(function () use ($token): TripAccessToken {
            if (! Gate::allows('kiosk.token.issue')) {
                throw new \InvalidArgumentException('Only the seat that can issue a driver link can close it (§9.3).');
            }

            $token->forceFill(['revoked' => true])->save();

            $this->audit->security(SecurityEventKind::KioskTokenRevoked, [
                'trip_access_token_id' => $token->id,
                'trip_id' => $token->trip_id,
                'driver_id' => $token->driver_id,
            ]);

            return $token->refresh();
        });
    }
}
