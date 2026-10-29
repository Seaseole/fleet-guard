<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Security\Models\UserSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §9.2's "log out everywhere". `$exceptHash` is what turns the same control into Breeze's older
 * "log out other browser sessions": a person who is standing at the depot desk with a suspicious phone
 * in their hand wants the phone gone and the desk stay open, in one click.
 *
 * Passing nothing revokes the device making the request too, which is the literal reading of the label
 * and the only safe default when the reason is a lost laptop.
 */
final class RevokeEverySession
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return int the number of devices cut off */
    public function __invoke(User $actor, ?string $exceptHash = null): int
    {
        return DB::transaction(function () use ($actor, $exceptHash): int {
            $rows = UserSession::query()
                ->where('user_id', $actor->id)
                ->whereNull('revoked_at')
                ->when($exceptHash !== null, fn ($query) => $query->where('id', '!=', $exceptHash))
                ->lockForUpdate()
                ->get();

            $revokedAt = Carbon::now();

            foreach ($rows as $row) {
                $row->forceFill(['revoked_at' => $revokedAt])->save();
            }

            $this->audit->security(SecurityEventKind::SessionRevoked, [
                'count' => $rows->count(),
                'scope' => $exceptHash === null ? 'every_device' : 'other_devices',
                'target_user' => $actor->id,
            ]);

            return $rows->count();
        });
    }
}
