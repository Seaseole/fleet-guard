<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed-in device (§9.2). The row is the application's own record of a session, written by
 * `TrackUserSessions` on the request that created it and compared against on every request after it.
 *
 * Its primary key is the SHA-256 of the framework session id, never the id itself: a readable table of
 * live session ids would hand anyone who could read it the ability to become that user, and nothing in
 * the screen needs the unhashed value.
 *
 * @property string $id
 * @property int $company_id
 * @property int $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property Carbon $last_seen_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property bool $two_factor_ok
 */
#[Hidden(['ip_address', 'user_agent'])]
final class UserSession extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'ip_address',
        'user_agent',
        'created_at',
        'last_seen_at',
        'expires_at',
        'two_factor_ok',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'two_factor_ok' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * The single question the middleware asks. Idle expiry and §9.1's absolute cap are both folded into
     * `expires_at` when the row is touched, so there is one clock here rather than two that can disagree.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && $this->expires_at->isFuture();
    }

    /** @return list<self> */
    public static function activeFor(int $userId): array
    {
        return array_values(self::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_seen_at')
            ->get()
            ->all());
    }
}
