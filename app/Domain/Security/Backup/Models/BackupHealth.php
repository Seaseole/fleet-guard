<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The health row: the answer to "when did this company's ledger last leave the building, and did
 * the copy survive being read back?"
 *
 * `job` is one of the two scheduled names — `offsite_export` writes the copy, `offsite_verify` reads
 * it back. Two rows rather than one because a fresh export that has never been verified and a verify
 * with no export behind it are different failures, and one timestamp cannot say which.
 *
 * @property string $id
 * @property int $company_id
 * @property string $job
 * @property bool $ok
 * @property Carbon $last_run_at
 * @property Carbon|null $last_success_at
 * @property string|null $object_key
 * @property int|null $bytes
 * @property int|null $rows
 * @property int|null $tables
 * @property string|null $sha256
 * @property string|null $detail
 */
final class BackupHealth extends Model
{
    use BelongsToCompany;

    public const JOB_EXPORT = 'offsite_export';

    public const JOB_VERIFY = 'offsite_verify';

    protected $table = 'backup_health';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'job',
        'ok',
        'last_run_at',
        'last_success_at',
        'object_key',
        'bytes',
        'rows',
        'tables',
        'sha256',
        'detail',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'bytes' => 'integer',
            'rows' => 'integer',
            'tables' => 'integer',
        ];
    }

    /** The row that tells the dashboard whether the last run was good. */
    public static function forJob(string $job): ?self
    {
        return self::query()->where('job', $job)->first();
    }

    protected static function booted(): void
    {
        self::creating(function (self $row): void {
            $row->id ??= (string) Str::ulid();
        });
    }
}
