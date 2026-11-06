<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Security\Actions\AcceptInvitation;
use App\Domain\Security\Policies\InvitationPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use invitation to a staff account. Public registration is off, so this row plus
 * {@see AcceptInvitation()} is the only way a user record comes into
 * existence — which is why the row carries the role it grants, the address it was sent to, and the
 * proof of what happened to it.
 *
 * The plaintext token is never stored. It is shown once, inside the emailed link, and the row keeps
 * only its SHA-256 hash, so a database dump is not a folder of live enrolment links.
 *
 * @property int $id
 * @property int $company_id
 * @property string $email
 * @property string $role_key
 * @property string $token_hash
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['token_hash'])]
#[UsePolicy(InvitationPolicy::class)]
final class Invitation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'email',
        'role_key',
        'invited_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'invited_by' => 'integer',
            'accepted_user_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function role(): RoleKey
    {
        return RoleKey::fromKey($this->role_key);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Compared case-insensitively: an invitation sent to `Eugene@Company.BW` and accepted by a
     * mailbox that reports `eugene@company.bw` is the same person, and forcing the match to be byte
     * exact would only teach people to re-send invites.
     */
    public function matchesEmail(string $email): bool
    {
        return mb_strtolower(trim($email)) === mb_strtolower($this->email);
    }
}
