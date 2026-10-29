<?php

namespace App\Models;

use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Identity\PermissionCatalogue;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\Company;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// company_id is deliberately absent: the tenant a user belongs to is set by the invite
// Action, never assigned from request input (T2). It is also absent from BelongsToCompany,
// because authenticating a user means finding them by email before any company context
// exists — that is the one place an unscoped read is correct, and it is the reason the
// exception is written down rather than left to be rediscovered.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'company_id' => 'integer',
        ];
    }

    public function requiresMfa(): bool
    {
        return $this->hasRole(RoleKey::Owner->value)
            || $this->hasAnyPermission(PermissionCatalogue::financialNames());
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The topbar bell's count (§DESIGN-SYSTEM §7: "things needing action", not "unread messages").
     *
     * It is an accessor rather than a column because the answer is per person *and* per register state:
     * `alerts` has no user on it, and `notifications` has no idea whether the thing it delivered is
     * resolved. {@see Alert::countUnreadDeliveriesFor()} is where the two are reconciled, and this is
     * the only place the topbar is allowed to get its number from — a second query in a second component
     * is how a bell and a register start disagreeing.
     *
     * @return Attribute<int, null>
     */
    protected function unreadAlertsCount(): Attribute
    {
        return Attribute::get(fn (): int => Alert::countUnreadDeliveriesFor($this));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
