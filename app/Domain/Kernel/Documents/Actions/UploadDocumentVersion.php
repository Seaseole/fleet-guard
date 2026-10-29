<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\ContentSniffer;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use App\Domain\Kernel\Documents\Exceptions\LifecycleTransitionRefused;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use App\Domain\Kernel\Documents\Scan\VirusScanner;
use App\Domain\Kernel\Documents\ScanVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one door through which bytes reach the private disk (§14.4), and the only place a version row
 * is ever created.
 *
 * The order of the steps is the contract:
 *
 *  1. sniff the content and judge it against the type's allowlist and cap — before anything is
 *     written, so a refused file never costs a disk object;
 *  2. scan, while the bytes are still the local temporary upload; after `put()` they are wherever
 *     the disk lives and the scanner needs a copy fetched back;
 *  3. write, then create the row from what the write measured.
 *
 * Step 3's order matters more than it looks. A row whose path or digest is wrong is worse than a
 * rejected upload: it lists in every screen, refuses to download forever, and the CHECKs on `path`
 * and `sha256` cannot catch it because the values are well-formed and simply not about the file that
 * exists. So the row is written *from* the store's measurement, never alongside it.
 *
 * The supersede happens inside the same transaction as the insert, and the §10.8 unique index is
 * what makes it safe rather than this code: two uploads racing would both read "no other current
 * version" and both insert, and the index across `current_slot` is the thing that makes the second
 * one fail instead of a document with two truths.
 */
final class UploadDocumentVersion
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly ContentSniffer $sniffer,
        private readonly VirusScanner $scanner,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  string|null  $replacedReason  why this copy replaces the one on file (§10.8 keeps it on the version)
     */
    public function handle(Document $document, DocumentInput $input, ?int $uploadedBy = null, ?string $replacedReason = null): DocumentVersion
    {
        $type = $document->type;
        $was = $document->lifecycle;

        if (! $was->acceptsNewVersion()) {
            throw LifecycleTransitionRefused::newVersionOn($was, $document->id);
        }

        $mime = $this->sniffer->sniff($input->absolutePath);
        $allowed = $type->allowedMimeTypes();

        if (! in_array($mime, $allowed, true)) {
            throw InvalidDocumentFile::mimeNotAllowed($type, $mime, $allowed);
        }

        $size = filesize($input->absolutePath);

        if ($size === false) {
            throw InvalidDocumentFile::unreadable($input->absolutePath.' could not be measured.');
        }

        if ($size > $type->maxBytes()) {
            throw InvalidDocumentFile::tooLarge($type, $size, $type->maxBytes());
        }

        $scan = $this->scanner->scan($input->absolutePath, $input->clientName);

        if ($scan->verdict === ScanVerdict::Infected) {
            // Refused at the door: the bytes are never written, so an infected file does not exist
            // on the private disk at all. §14.4's scan is an intake control, not a badge on a listing.
            throw InvalidDocumentFile::infected($scan->detail);
        }

        $versionId = (string) Str::ulid();
        $storedPath = null;

        try {
            /** @var DocumentVersion $version */
            $version = DB::transaction(function () use ($document, $input, $was, $mime, $scan, $uploadedBy, $replacedReason, $versionId, &$storedPath): DocumentVersion {
                $nextSeq = (int) DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->max('version_seq') + 1;

                $storedPath = $this->store->pathFor($document, $versionId, $nextSeq);
                $measured = $this->store->put($input->absolutePath, $storedPath);

                // The old copy is disowned before the new one claims the slot, so the unique index is
                // never asked to hold two.
                DocumentVersion::query()
                    ->where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false, 'replaced_reason' => $replacedReason]);

                $version = DocumentVersion::create([
                    'id' => $versionId,
                    'document_id' => $document->id,
                    'version_seq' => $nextSeq,
                    'disk' => DocumentStore::Disk,
                    'path' => $storedPath,
                    'mime' => $mime,
                    'size_bytes' => $measured['size_bytes'],
                    'sha256' => $measured['sha256'],
                    'is_current' => true,
                    'virus_scan_status' => $scan->verdict,
                    // The DB CHECK refuses a non-pending verdict with no scan time, and refuses a
                    // pending one with a time: the column pair is the record, not this line's opinion.
                    'scanned_at' => $scan->verdict === ScanVerdict::Pending ? null : now(),
                    'scan_detail' => $scan->detail,
                    'uploaded_at' => now(),
                    'uploaded_by' => $uploadedBy,
                ]);

                $this->publish($document, $was);

                $this->audit->security(SecurityEventKind::DocumentUploaded, [
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                    'version_seq' => $version->version_seq,
                    'type' => $document->type->value,
                    'mime' => $mime,
                    'declared_mime' => $input->declaredMime !== '' ? $input->declaredMime : null,
                    'size_bytes' => $measured['size_bytes'],
                    'sha256' => $measured['sha256'],
                    'scanner' => (string) config('fleet.documents.scanner', 'none'),
                ], $uploadedBy);

                return $version;
            });
        } catch (Throwable $e) {
            // The transaction rolls the row back; it cannot roll back a disk object. An orphan on a
            // private disk is invisible to every screen and would survive forever otherwise.
            if ($storedPath !== null) {
                $this->store->deletePath($storedPath);
            }

            throw $e;
        }

        return $version;
    }

    /**
     * A document becomes `current` when its first version is published (§10.8). A document already
     * `current` stays `current` — the version behind it is what got superseded, not the paper.
     *
     * This is the only reading the stored axis survives: if publishing a newer scan moved the
     * *document* to `superseded`, no document could hold more than one live version and the register
     * would mark a truck's current licence superseded the moment somebody re-uploaded it.
     */
    private function publish(Document $document, Lifecycle $was): void
    {
        if ($was !== Lifecycle::Draft) {
            return;
        }

        if (! $was->canTransitionTo(Lifecycle::Current)) {
            throw LifecycleTransitionRefused::between($was, Lifecycle::Current, $document->id);
        }

        $document->forceFill(['lifecycle' => Lifecycle::Current])->save();

        $this->audit->operational(
            'documents',
            $document->id,
            $was->value,
            Lifecycle::Current->value,
            null,
            ['via' => 'first version published'],
        );
    }
}
