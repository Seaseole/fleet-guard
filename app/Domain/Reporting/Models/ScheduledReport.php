<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One nomination. `day_of_week` is 0=Sunday (PHP's `w`), `day_of_month` 1..28 —
 * the ranges are the DB CHECK's, repeated here as casts and nothing more.
 *
 * @property string $id
 * @property int $company_id
 * @property string $report
 * @property string $frequency
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property bool $enabled
 * @property int|null $set_by
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ScheduledReport extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'report', 'frequency', 'day_of_week', 'day_of_month',
        'enabled', 'set_by', 'last_sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'enabled' => 'boolean',
            'set_by' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }

    public function isDueOn(Carbon $today): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return match ($this->frequency) {
            'daily' => true,
            'weekly' => (int) $today->format('w') === (int) $this->day_of_week,
            // 'j', the day of the month — 'n' is the month number, and asking the wrong half
            // of the date is exactly the bug a due-check must not ship with.
            'monthly' => (int) $today->format('j') === (int) $this->day_of_month,
            default => false,
        };
    }

    /**
     * The window the mail answers for today: yesterday, the last seven days, the past month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(Carbon $today): array
    {
        $yesterday = $today->copy()->subDay()->startOfDay();

        return match ($this->frequency) {
            'weekly' => [$today->copy()->subDays(7)->startOfDay(), $yesterday],
            'monthly' => [
                $today->copy()->startOfMonth()->subMonth()->startOfMonth(),
                $today->copy()->startOfMonth()->subDay(),
            ],
            default => [$yesterday, $yesterday],
        };
    }
}
