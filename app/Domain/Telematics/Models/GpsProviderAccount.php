<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * §4.4 GPS_PROVIDER_ACCOUNT — the feed a company ingests from.
 *
 * The simulator and a Phase 7 gateway are the same row with a different `adapter`. Nothing here
 * decrypts the credential: a provider key is a secret like a bank number, and the screen that needs
 * it will call `FieldCipher` behind its own gate rather than letting a map read expose it (§9.5).
 *
 * @property int $id
 * @property int $company_id
 * @property string $adapter
 * @property string|null $endpoint
 * @property string|null $api_key_encrypted
 * @property string|null $key_version
 * @property bool $active
 * @property Carbon|null $last_sync_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GpsProviderAccount extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'adapter',
        'endpoint',
        'active',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return ucfirst($this->adapter).' feed';
    }
}
