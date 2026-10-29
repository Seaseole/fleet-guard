<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\People\DriverStatus;
use App\Domain\People\Policies\DriverPolicy;
use App\Domain\Security\DataGovernance\HasMaskedSecret;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 DRIVER — the employee as a driver, and the holder of the two dates that decide dispatch.
 *
 * `licence_expires_on` and `permit_expires_on` are the single source §10.2 reads at
 * `planned → assigned`. The scan of the card is a §4.4 document attached to this row; it carries no
 * expiry of its own, so a lapsed licence cannot be reported twice under two different dates.
 *
 * A null expiry is **not** "valid forever": it means no paper has been recorded, and
 * {@see licenceValidOn()} answers false so an incomplete record cannot silently dispatch a truck.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string|null $licence_no_masked
 * @property string|null $key_version
 * @property string|null $licence_code
 * @property Carbon|null $licence_expires_on
 * @property Carbon|null $permit_expires_on
 * @property int $default_allowance_rate_base
 * @property string|float|null $fuel_efficiency_baseline_10_2
 * @property DriverStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property bool $kiosk_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(DriverPolicy::class)]
final class Driver extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition, HasMaskedSecret;

    /** The §9.5 column prefix: `licence_no_encrypted` + `licence_no_masked`. */
    public const LICENCE = 'licence_no';

    /** @var list<string> */
    protected $auditExclude = [
        'licence_no_encrypted',
    ];

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'licence_code',
        'licence_expires_on',
        'permit_expires_on',
        'default_allowance_rate_base',
        'fuel_efficiency_baseline_10_2',
        'kiosk_enabled',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'employee_id' => 'integer',
            'licence_expires_on' => 'date',
            'permit_expires_on' => 'date',
            'default_allowance_rate_base' => 'integer',
            'fuel_efficiency_baseline_10_2' => 'float',
            'status' => DriverStatus::class,
            'state_changed_at' => 'datetime',
            'kiosk_enabled' => 'boolean',
        ];
    }

    /**
     * §10.1's table. `left` is terminal: rehiring is a new driver row against the same employee
     * (or no row at all), because a trail that says the same person left twice is unreadable.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            DriverStatus::Active->value => [DriverStatus::Standby->value, DriverStatus::Suspended->value, DriverStatus::Left->value],
            DriverStatus::Standby->value => [DriverStatus::Active->value, DriverStatus::Suspended->value, DriverStatus::Left->value],
            DriverStatus::Suspended->value => [DriverStatus::Active->value, DriverStatus::Standby->value, DriverStatus::Left->value],
            DriverStatus::Left->value => [],
        ];
    }

    /**
     * Suspension is a safety decision, so it needs a reason as much as leaving does — "why was this
     * driver taken off the road in March" must survive the person who made the call.
     *
     * @return list<string>
     */
    protected function transitionsRequiringReason(): array
    {
        return [DriverStatus::Suspended->value, DriverStatus::Left->value];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changeLicenceNo(?string $licenceNo): void
    {
        $this->writeMaskedSecret(self::LICENCE, $licenceNo);
    }

    public function maskedLicenceNo(): string
    {
        return $this->maskedSecret(self::LICENCE);
    }

    /** Date-only, as §10.2 requires: a paper valid "today" is valid on today's departure. */
    public function licenceValidOn(CarbonInterface $on): bool
    {
        return $this->licence_expires_on !== null
            && $this->licence_expires_on->greaterThanOrEqualTo(Carbon::instance($on)->startOfDay());
    }

    public function permitValidOn(CarbonInterface $on): bool
    {
        return $this->permit_expires_on !== null
            && $this->permit_expires_on->greaterThanOrEqualTo(Carbon::instance($on)->startOfDay());
    }

    /** Status plus both papers — the one question dispatch asks before it names a driver. */
    public function eligibleOn(CarbonInterface $on): bool
    {
        return $this->status->mayBeDispatched()
            && $this->licenceValidOn($on)
            && $this->permitValidOn($on);
    }
}
