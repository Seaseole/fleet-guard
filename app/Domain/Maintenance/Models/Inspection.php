<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\InspectionKind;
use App\Domain\Maintenance\InspectionResult;
use App\Domain\Operations\Models\Trip;
use App\Domain\People\Models\Driver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * INSPECTION — one safety check, with the answers that were given.
 *
 * The row is evidence, so it is written once and never edited: a correction is a second inspection,
 * which is what a register of checks needs. `checklist` holds `{item: bool}` for exactly the items
 * {@see InspectionKind::checklist()} declared at the time — the Action refuses a missing answer — and
 * `defects` holds the lines a person wrote about what was wrong.
 *
 * The model exposes the reading the register prints (`failedChecklistItems()`); the *rule* that a pass
 * cannot carry a defect lives in {@see InspectionResult} and is enforced by the Action. A model method
 * that recalculated the verdict would be a second definition of `result`.
 *
 * @property string $id
 * @property int $company_id
 * @property InspectionKind $kind
 * @property int $truck_id
 * @property string|null $trip_id
 * @property string|null $work_order_id
 * @property int|null $driver_id
 * @property Carbon $done_at
 * @property InspectionResult $result
 * @property array<string, bool>|null $checklist
 * @property list<string>|null $defects
 * @property list<string>|null $photo_document_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Inspection extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'kind',
        'truck_id',
        'trip_id',
        'work_order_id',
        'driver_id',
        'done_at',
        'result',
        'checklist',
        'defects',
        'photo_document_ids',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'kind' => InspectionKind::class,
            'truck_id' => 'integer',
            'driver_id' => 'integer',
            'done_at' => 'datetime',
            'result' => InspectionResult::class,
            'checklist' => 'array',
            'defects' => 'array',
            'photo_document_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $inspection): void {
            $inspection->id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * The checklist items answered "no", as key => the question that was asked. Keys that the kind's
     * list no longer carries are skipped rather than printed with a raw key.
     *
     * @return array<string, string>
     */
    public function failedChecklistItems(): array
    {
        $questions = $this->kind->checklist();
        $answers = $this->checklist ?? [];
        $failed = [];

        foreach ($questions as $key => $question) {
            if (($answers[$key] ?? true) === false) {
                $failed[$key] = $question;
            }
        }

        return $failed;
    }

    /** @return list<string> */
    public function defectLines(): array
    {
        return array_values(array_filter(
            $this->defects ?? [],
            static fn (mixed $line): bool => is_string($line) && trim($line) !== '',
        ));
    }

    public function hasPhotos(): bool
    {
        return ($this->photo_document_ids ?? []) !== [];
    }

    /** "BWD1234K · Pre-trip walk-around · 30 Oct 2026" — the register's name for an inspection. */
    public function label(): string
    {
        return sprintf(
            '%s · %s · %s',
            $this->truck?->label() ?? 'Truck #'.$this->truck_id,
            $this->kind->label(),
            $this->done_at?->format('d M Y') ?? '',
        );
    }
}
