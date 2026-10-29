<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Removing one copy of a document, bytes and row together (§9.3's ⚑ documents.delete).
 *
 * Hard delete, and that is the only honest option: a version row exists to point at bytes, so
 * "soft-deleting" it leaves a pointer to a file that is still in the bucket and still downloadable
 * by anything that finds the path. Deleting the bytes without the row is worse — a version that
 * lists, claims a digest and serves nothing.
 *
 * The reason is mandatory even though §4.4 only demands it for a *destructive state transition*:
 * deleting the evidence is the most destructive thing a document screen can do, and a trail that
 * records the actor but not the why answers half the question an auditor asks.
 */
final class DeleteDocumentVersion
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(DocumentVersion $version, string $reason, ?int $byUserId = null): void
    {
        if (trim($reason) === '') {
            // Enforced here rather than only in the screen: §9.3's kiosk path, a future API (§14.9)
            // and this verifier all call the same door, and a reason that is optional at the door is
            // optional.
            throw new InvalidArgumentException('Deleting a version requires the reason that will appear in the trail (§9.5).');
        }

        /** @var Document $document */
        $document = $version->document;
        $wasCurrent = $version->is_current;
        $sequence = $version->version_seq;

        DB::transaction(function () use ($version, $document, $reason, $byUserId, $wasCurrent, $sequence): void {
            if ($wasCurrent) {
                // Release the §10.8 slot before the row goes: the unique index across `current_slot`
                // is what guarantees one current version, and a document whose last current copy is
                // deleted legitimately has none — "no copy on file" is a state the register shows.
                $version->forceFill(['is_current' => false])->save();
            }

            $this->store->delete($version);
            $version->delete();

            $this->audit->security(SecurityEventKind::DocumentVersionDeleted, [
                'document_id' => $document->id,
                'version_seq' => $sequence,
                'was_current' => $wasCurrent,
                'mime' => $version->mime,
                'size_bytes' => $version->size_bytes,
                'sha256' => $version->sha256,
                'reason' => $reason,
            ], $byUserId);

            if ($wasCurrent) {
                $this->audit->operational(
                    'documents',
                    $document->id,
                    $document->lifecycle->value,
                    $document->lifecycle->value,
                    $reason,
                    ['version_deleted' => $sequence, 'current_versions_remaining' => $document->currentVersion()->exists()],
                );
            }
        });
    }
}
