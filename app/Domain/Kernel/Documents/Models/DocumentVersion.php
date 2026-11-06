<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Models;

use App\Domain\Kernel\Documents\Exceptions\DocumentVersionIsImmutable;
use App\Domain\Kernel\Documents\ScanVerdict;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * DOCUMENT_VERSION — immutable bytes with exactly one mutable fact about them: whether they
 * are the current copy.
 *
 * The split is the design. Uploading a new scan creates another row instead of overwriting the old
 * one, which is what lets anyone later ask "what did we hold on 14 March" — a question an overwrite
 * makes unanswerable. So the columns that describe the bytes are refused in code (the same layer-1
 * role the design gives the ledger), and only the pointer, the scan result and the supersede reason are
 * writable.
 *
 * `current_slot` is a STORED generated column and this class never writes it: the database computes
 * it from `is_current`, and the unique index across it is what makes two current versions
 * impossible below the application.
 *
 * @property string $id
 * @property int $company_id
 * @property string $document_id
 * @property int $version_seq
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property bool $is_current
 * @property ScanVerdict $virus_scan_status
 * @property Carbon|null $scanned_at
 * @property string|null $scan_detail
 * @property Carbon $uploaded_at
 * @property int|null $uploaded_by
 * @property string|null $replaced_reason
 * @property-read string|null $current_slot
 */
final class DocumentVersion extends Model
{
    use BelongsToCompany;

    /**
     * The columns that describe the bytes. An update that dirties any of them is refused, because
     * it would be editing history rather than appending a version.
     *
     * @var list<string>
     */
    public const ByteColumns = ['document_id', 'version_seq', 'disk', 'path', 'mime', 'size_bytes', 'sha256', 'uploaded_at', 'uploaded_by'];

    protected $table = 'document_versions';

    /**
     * No timestamps on a version: `uploaded_at` and `scanned_at` are the two dated facts that
     * matter, and a `updated_at` on an immutable row would suggest it gets edited.
     */
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'document_id', 'version_seq', 'disk', 'path', 'mime', 'size_bytes', 'sha256',
        'is_current', 'virus_scan_status', 'scanned_at', 'scan_detail', 'uploaded_at', 'uploaded_by',
        'replaced_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_seq' => 'integer',
            'size_bytes' => 'integer',
            'is_current' => 'boolean',
            'virus_scan_status' => ScanVerdict::class,
            'scanned_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $version): void {
            $version->id ??= (string) Str::ulid();
        });

        self::updating(function (self $version): void {
            /** @var list<string> $dirty */
            $dirty = array_keys($version->getDirty());
            $written = array_values(array_intersect($dirty, self::ByteColumns));

            if ($written !== []) {
                // The rejected values are dropped from the in-memory model before the throw. Without
                // this, a caller that catches the exception and saves something legitimate — a scan
                // note, the current flag — is refused again, this time naming a column nobody is
                // editing any more. The row stays as the database has it, which is the point.
                foreach ($written as $column) {
                    $version->setAttribute($column, $version->getOriginal($column));
                }

                throw DocumentVersionIsImmutable::columns($version, $written);
            }
        });
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        return $this->size_bytes >= 1048576
            ? sprintf('%.1f MB', $this->size_bytes / 1048576)
            : sprintf('%d KB', (int) ceil($this->size_bytes / 1024));
    }

    /**
     * Whether these bytes may be served here.
     *
     * `infected` never is. `pending` depends on whether this environment actually has a scanner:
     * with one, the download waits for the queue; without one, the strict type allowlist is the
     * substituted control and the file serves. {@see ScanVerdict::isServable()} carries the Q8
     * reasoning — the point of passing the flag here is that no call site may decide for itself.
     */
    public function isServable(): bool
    {
        return $this->virus_scan_status->isServable(config('fleet.documents.scanner') !== 'none');
    }
}
