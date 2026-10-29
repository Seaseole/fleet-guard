<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\RetentionClass;
use Carbon\CarbonInterface;

/**
 * A register entry with no bytes yet (§10.8's `draft`).
 *
 * Separate from {@see UploadDocumentVersion} because the two things a clerk does are two things:
 * "we have to get this permit, file the slot" and "here is the scan". Merging them would mean a
 * document cannot exist until a file exists, which is exactly how paper trails end up tracked in a
 * notebook instead of in the system.
 *
 * An empty `expires_on` on a type that has one is not refused: the date is often unknown at the
 * moment of filing and always unknown on the day the paperwork is requested. The register's job is
 * to make the gap visible — {@see DocumentType::expires()} is what the screen marks it with — not to
 * demand a value the clerk does not have.
 */
final class RegisterDocument
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<string>  $visibilityRoles
     */
    public function handle(
        DocumentType $type,
        string $title,
        ?string $morphType = null,
        ?string $morphId = null,
        ?CarbonInterface $expiresOn = null,
        RetentionClass $retention = RetentionClass::Operational,
        array $visibilityRoles = [],
        bool $confidential = false,
        ?string $notes = null,
    ): Document {
        $document = Document::create([
            'type' => $type,
            'title' => $title,
            'morph_type' => $morphType,
            'morph_id' => $morphId,
            'expires_on' => $expiresOn,
            'retention_class' => $retention,
            // An empty list is stored as null, not `[]`: the column's meaning is "nobody has narrowed
            // this yet", and a JSON empty array would read as "deliberately visible to no one".
            'visibility' => $visibilityRoles === [] ? null : $visibilityRoles,
            'confidential' => $confidential,
            'notes' => $notes,
        ]);

        $this->audit->operational('documents', $document->id, null, Lifecycle::Draft->value, null, [
            'title' => $document->title,
            'type' => $type->value,
        ]);

        return $document;
    }
}
