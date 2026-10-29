<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\People\Policies\EmployeePolicy;
use App\Domain\Security\DataGovernance\HasMaskedSecret;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 EMPLOYEE — the person on the books, without the statutory payroll that Phase 5 owns.
 *
 * The national ID uses the §9.5 trio. Phase 1 has no field gate that reveals it (§9.4 names five,
 * and national identity is not one), so the mask is the only form any screen shows: the ciphertext
 * is stored so that when a gate is justified the plaintext is already encrypted rather than in a
 * spreadsheet, and no code path decrypts it today.
 *
 * @property int $id
 * @property int $company_id
 * @property string $employee_no
 * @property string $name
 * @property string|null $national_id_masked
 * @property string|null $key_version
 * @property string|null $role_or_grade
 * @property Carbon $hired_on
 * @property Carbon|null $left_on
 * @property int $gross_salary_base
 * @property string $pay_frequency
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(EmployeePolicy::class)]
final class Employee extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, HasMaskedSecret;

    /** The §9.5 column prefix: `national_id_encrypted` + `national_id_masked`. */
    public const NATIONAL_ID = 'national_id';

    /** @var list<string> */
    protected $auditExclude = [
        'national_id_encrypted',
    ];

    /** @var list<string> */
    protected $fillable = [
        'employee_no',
        'name',
        'role_or_grade',
        'hired_on',
        'left_on',
        'gross_salary_base',
        'pay_frequency',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'hired_on' => 'date',
            'left_on' => 'date',
            'gross_salary_base' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function changeNationalId(?string $nationalId): void
    {
        $this->writeMaskedSecret(self::NATIONAL_ID, $nationalId);
    }

    public function maskedNationalId(): string
    {
        return $this->maskedSecret(self::NATIONAL_ID);
    }

    /** Salary in minor units; the screen formats it, the model never holds a float. */
    public function grossSalaryMinor(): int
    {
        return (int) $this->gross_salary_base;
    }
}
