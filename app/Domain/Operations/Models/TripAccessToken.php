<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\TripAccessScope;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * TRIP_ACCESS_TOKEN — the secret a driver's phone carries instead of an account.
 *
 * Two columns are the whole security story and both are hidden from serialisation: `token_hash` and
 * `pin_hash`. Nothing in this codebase can print them back, which is the point — a support query, an
 * audit export or a leaked read of this table yields hashes, and the "a token cannot read another
 * trip" is then the only thing left to attack.
 *
 * There is no `driver()` relation. D5 keeps Operations from importing People's models, so the
 * kiosk asks {@see DriverDirectory} for a name and this row keeps the id.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_id
 * @property int $driver_id
 * @property string $token_hash
 * @property string $pin_hash
 * @property string $scopes
 * @property bool $revoked
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_ip
 * @property int|null $issued_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TripAccessToken extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id', 'company_id', 'trip_id', 'driver_id', 'token_hash', 'pin_hash',
        'scopes', 'revoked', 'expires_at', 'last_used_at', 'last_ip', 'issued_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'pin_hash'];

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'driver_id' => 'integer',
            'issued_by' => 'integer',
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    /** @return list<TripAccessScope> */
    public function scopeList(): array
    {
        return TripAccessScope::fromCsv($this->scopes);
    }

    public function hasScope(TripAccessScope $scope): bool
    {
        return in_array($scope, $this->scopeList(), true);
    }

    /**
     * The PIN as prints it: six digits the driver may type, bound to the link it came with.
     *
     * Binding matters — an unbound PIN hash would let a PIN lifted from one trip's note be tried against
     * every other token in the company. Constant-time comparison, because this is the check an attacker
     * gets to repeat.
     */
    public function hasPinFor(string $pin, string $secret): bool
    {
        return hash_equals($this->pin_hash, hash('sha256', $pin.'|'.$secret));
    }

    public function isLive(): bool
    {
        return ! $this->revoked && $this->expires_at->isFuture();
    }

    /**
     * Why this token is closed, in the words an operator needs.
     *
     * "Revoked" and "expired" look identical on a kiosk screen and have different fixes — one is a
     * dispatcher's decision, the other is a trip that ran long — so the row is asked which it is.
     */
    public function closedBecause(): ?string
    {
        if ($this->revoked) {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : null;
    }

    protected static function booted(): void
    {
        self::creating(static function (self $token): void {
            $token->id ??= (string) Str::ulid();
        });
    }
}
