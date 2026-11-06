<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Reporting\ReportExportFormat;
use App\Domain\Reporting\ReportExportStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A queued report file. The row is a ticket, not an archive: the bytes sit on the
 * private disk and the window names what they meant when they were built — a report of record
 * is rebuilt by re-asking the door, never by trusting an old export.
 *
 * @property string $id
 * @property int $company_id
 * @property string $report
 * @property ReportExportFormat $format
 * @property Carbon $window_from
 * @property Carbon $window_to
 * @property ReportExportStatus $status
 * @property int|null $actor_id
 * @property string|null $storage_key
 * @property string|null $filename
 * @property int|null $bytes
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReportExport extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'report', 'format', 'window_from', 'window_to',
        'status', 'actor_id', 'storage_key', 'filename', 'bytes', 'failure_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'window_from' => 'date',
            'window_to' => 'date',
            'actor_id' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isReady(): bool
    {
        return $this->status === ReportExportStatus::Ready;
    }
}
