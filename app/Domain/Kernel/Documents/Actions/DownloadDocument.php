<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\Exceptions\DocumentNotDownloadable;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use App\Domain\Kernel\Documents\ScanVerdict;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The private disk's only way out, in the order the guarantees are actually earned.
 *
 *  1. the lifecycle refuses a **voided** document — the paper is denied, not merely hidden;
 *  2. the scan verdict refuses infected bytes, and unscanned bytes wherever a scanner exists;
 *  3. the bytes are confirmed present before anything is recorded, so an audit row never claims a
 *     download that returned nothing;
 *  4. the trail is written **before** the body streams;
 *  5. the response carries `private, no-store` and `nosniff`.
 *
 * Step 4 is the one that looks wrong and is not. Writing the row after the stream would put it
 * inside a callback that the client can abandon halfway by closing the tab, and an abandoned
 * download is precisely the event worth having on record: the file left the disk. Recording it
 * before means an entry can exist for a read that failed mid-flight, which is the honest version of
 * "who read this" — the trail says the bytes were served, not that the reader finished them.
 *
 * The `Content-Type` is the type the store *sniffed* at upload, never the client's, and never
 * inline: a document served with `Content-Disposition: inline` is a document the browser will try to
 * run.
 */
final class DownloadDocument
{
    public function __construct(private readonly DocumentStore $store, private readonly AuditRecorder $audit) {}

    /**
     * @param  string  $via  what asked for the file, so the trail distinguishes a screen click from a kiosk
     */
    public function handle(DocumentVersion $version, ?int $actorId = null, string $via = 'screen'): StreamedResponse
    {
        /** @var Document $document */
        $document = $version->document;

        if ($document->lifecycle === Lifecycle::Voided) {
            throw DocumentNotDownloadable::voided($document->lifecycle);
        }

        if ($version->virus_scan_status === ScanVerdict::Infected) {
            throw DocumentNotDownloadable::infected($version);
        }

        if (! $version->isServable()) {
            throw DocumentNotDownloadable::unscanned();
        }

        if (! $this->store->exists($version)) {
            throw DocumentNotDownloadable::missingFromDisk($version);
        }

        $this->audit->security(SecurityEventKind::DocumentDownloaded, [
            'document_id' => $document->id,
            'document_title' => Str::limit($document->title, 80),
            'type' => $document->type->value,
            'version_id' => $version->id,
            'version_seq' => $version->version_seq,
            'is_current' => $version->is_current,
            'mime' => $version->mime,
            'size_bytes' => $version->size_bytes,
            'sha256' => $version->sha256,
            'via' => $via,
        ], $actorId);

        $stream = $this->store->readStream($version);

        if ($stream === null) {
            // Checked one statement above, and still re-read here: between the two the object can go,
            // and a stream that dies mid-response is a 200 with an empty body unless it is refused.
            throw DocumentNotDownloadable::missingFromDisk($version);
        }

        $name = $this->filename($document, $version);

        return response()
            ->stream(function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    // The tab can close halfway through a 15 MB customs bundle. Without the finally
                    // the handle is left for the process to collect, and on a worker that outlives
                    // the request that is a descriptor per abandoned download.
                    fclose($stream);
                }
            }, 200, [
                'Content-Type' => $version->mime,
                'Content-Length' => (string) $version->size_bytes,
                'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $name).'"',
                // Verbatim, and the two that matter most on shared dispatch machines:
                // `no-store` keeps a proxy from holding the file, and `nosniff` stops a browser
                // deciding that a document it was served is really a script.
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    /**
     * The name the reader sees. Built from the document title rather than the stored path, because
     * the path is a ULID and a clerk who downloads three licences must be able to tell which is
     * which afterwards; sanitised because a title is free text and a header is not.
     */
    private function filename(Document $document, DocumentVersion $version): string
    {
        $base = Str::of($document->title)
            ->ascii(' ')
            ->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')
            ->trim(' .-_')
            ->limit(80, '')
            ->value();

        $extension = match (true) {
            str_contains($version->mime, 'pdf') => 'pdf',
            str_contains($version->mime, 'jpeg') => 'jpg',
            str_contains($version->mime, 'png') => 'png',
            str_contains($version->mime, 'webp') => 'webp',
            str_contains($version->mime, 'plain') => 'txt',
            default => 'bin',
        };

        return ($base === '' ? 'document-'.$version->id : $base).'.'.$extension;
    }
}
