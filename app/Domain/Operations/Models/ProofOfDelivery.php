<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\PodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * PROOF_OF_DELIVERY — the evidence that one delivery happened, and who said so.
 *
 * `signature_document_id` and the photo are document ULIDs, not paths: bytes, MIME truth, retention and
 * signed download are the document module's job, so a POD inherits "keep the signed note for the
 * tax horizon" instead of re-inventing it next to a file column.
 *
 * `client_uuid` is the offline natural key. A retry after a dropped border signal must land on
 * this row rather than create a second delivery note, so the capture Action looks it up **before** it
 * writes anything and returns the row it found unchanged — the second attempt is not an error, it is the
 * same claim arriving twice.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_id
 * @property int|null $load_id
 * @property PodStatus $status
 * @property Carbon|null $delivered_at
 * @property string|null $receiver_name
 * @property string|float|null $quantity_received_tonnes_10_2
 * @property string|null $signature_document_id
 * @property array<string, mixed>|null $exceptions
 * @property string|null $client_uuid
 * @property string|null $captured_by_token_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProofOfDelivery extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id', 'company_id', 'trip_id', 'load_id', 'status', 'delivered_at', 'receiver_name',
        'quantity_received_tonnes_10_2', 'signature_document_id', 'exceptions',
        'client_uuid', 'captured_by_token_id',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'load_id' => 'integer',
            'status' => PodStatus::class,
            'delivered_at' => 'datetime',
            'quantity_received_tonnes_10_2' => 'float',
            'exceptions' => 'array',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    /**
     * The manifest line this proof answers, when it answers one.
     *
     * Named `loadRecord` rather than `load` because `Model::load(array $relations)` is Eloquent's own
     * eager-loading door — PHP refuses the override outright, and the fatal is named after a relation
     * nobody had finished calling yet.
     *
     * @return BelongsTo<Load, $this>
     */
    public function loadRecord(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signature_document_id');
    }

    /**
     * Gate for approval, asked of the row rather than of the caller: is there enough here to accept this
     * delivery as a fact?
     *
     * Exceptions must be **recorded or explicitly none** — `[]` means the driver ticked "nothing wrong",
     * while null means they never answered, and an approval that cannot tell those apart is how a
     * damaged load becomes invisible to the customer's claim.
     *
     * @return list<string> empty when the POD may be approved
     */
    public function approvalFindings(): array
    {
        $missing = [];

        if (blank($this->delivered_at)) {
            $missing[] = 'the delivery is undated';
        }

        if (blank($this->receiver_name)) {
            $missing[] = 'no receiver is named';
        }

        if (blank($this->signature_document_id)) {
            $missing[] = 'no signature on file';
        }

        if (blank($this->quantity_received_tonnes_10_2)) {
            $missing[] = 'no quantity received';
        }

        // Not `blank()`: `blank([])` is true, and `[]` is exactly how a driver records "nothing went
        // wrong". Only null — the column never written — is an unanswered question.
        if ($this->exceptions === null) {
            $missing[] = 'the exceptions question is unanswered';
        }

        return $missing;
    }

    protected static function booted(): void
    {
        self::creating(static function (self $pod): void {
            $pod->id ??= (string) Str::ulid();
            $pod->status ??= PodStatus::Pending;
        });
    }
}
