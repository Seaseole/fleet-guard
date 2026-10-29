<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root. Single-company today (ADR-0003), but a row rather than a constant so
 * that a second company becomes data rather than a rewrite.
 *
 * This is the only model in the Kernel that is deliberately not scoped by itself — read
 * `CurrentCompany` for why.
 *
 * @property int $id
 * @property string $name
 * @property string|null $registration_number
 * @property string|null $vat_number
 * @property string $base_currency_code
 * @property string $timezone
 * @property string $country_code
 * @property CarbonImmutable $fiscal_year_starts_on NOT NULL with a default, cast to a date
 * @property array<string, mixed>|null $settings
 */
final class Company extends Model
{
    protected $fillable = [
        'name',
        'registration_number',
        'vat_number',
        'base_currency_code',
        'timezone',
        'country_code',
        'fiscal_year_starts_on',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'fiscal_year_starts_on' => 'date',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
