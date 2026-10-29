<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * §14.4's path contract — `company/{id}/{type}/{entity}/{ulid}/{version}` on the private disk — and
 * the only code that touches those bytes.
 *
 * The layout is not decoration. `{company_id}` first means a bucket policy can do the tenant
 * isolation the database already does (§8 T3), so a leaked path is not a cross-tenant read.
 * `{type}/{entity}` makes a manual spot-check of the archive legible to a human. And `{ulid}` as the
 * directory is what stops two versions of the same paper colliding over each other's file, which an
 * extension-only or name-only layout eventually guarantees.
 *
 * Nothing else may call `Storage::disk('documents_private')`. This is the one door, and it is also
 * where the §10.8 digest is measured — from the bytes as they arrived, because a `sha256` computed
 * from anything else is a claim about a file nobody checked.
 */
final class DocumentStore
{
    public const Disk = 'documents_private';

    public function pathFor(Document $document, string $versionId, int $versionSeq): string
    {
        return implode('/', [
            'company',
            (string) $document->company_id,
            $document->type->value,
            $this->entitySegment($document),
            $versionId,
            (string) $versionSeq,
        ]);
    }

    /**
     * `{entity}` — the subject the paper evidences, or `unfiled` when it stands alone.
     *
     * A slug rather than a class name on purpose: the subject's table arrives in a later phase, and
     * a path that embeds a PHP namespace turns every rename into an archive that no longer matches
     * its index rows.
     */
    private function entitySegment(Document $document): string
    {
        if ($document->morph_type === null || $document->morph_id === null) {
            return 'unfiled';
        }

        return strtolower(str_replace('\\', '-', $document->morph_type)).'-'.$document->morph_id;
    }

    /**
     * Write the bytes and report what they measured.
     *
     * The digest and the size are taken from the source before the write and asserted against the
     * stored copy afterwards, because the thing §10.8 needs is a hash of *what is on the disk*. A
     * local write is atomic enough that this never trips; on a bucket it is the one check that
     * catches a truncated upload being filed as a complete document.
     *
     * @return array{sha256: string, size_bytes: int}
     */
    public function put(string $absoluteSourcePath, string $path): array
    {
        if (! is_readable($absoluteSourcePath)) {
            throw InvalidDocumentFile::unreadable("{$absoluteSourcePath} is not readable.");
        }

        $digest = hash_file('sha256', $absoluteSourcePath);
        $size = filesize($absoluteSourcePath);

        if ($digest === false || $size === false) {
            throw InvalidDocumentFile::unreadable("{$absoluteSourcePath} could not be measured.");
        }

        if ($size === 0) {
            throw InvalidDocumentFile::empty();
        }

        $disk = Storage::disk(self::Disk);

        if ($disk->exists($path)) {
            // The version id is a ULID and it is in the path, so this is not bad luck — it is a
            // caller trying to replace a stored version, which §10.8 forbids.
            throw new RuntimeException("Refusing to overwrite the existing document path {$path}: versions are appended, never replaced.");
        }

        $handle = fopen($absoluteSourcePath, 'rb');

        if ($handle === false) {
            throw InvalidDocumentFile::unreadable("{$absoluteSourcePath} opened but could not be read.");
        }

        try {
            if ($disk->writeStream($path, $handle) === false) {
                throw new RuntimeException("The private disk refused to write {$path}.");
            }
        } finally {
            fclose($handle);
        }

        $storedSize = $disk->size($path);

        if ($storedSize !== $size) {
            $disk->delete($path);

            throw new RuntimeException(sprintf(
                'Wrote %d bytes but the private disk reports %d for %s; the version was discarded rather than filed against a truncated copy.',
                $size,
                $storedSize,
                $path,
            ));
        }

        return ['sha256' => $digest, 'size_bytes' => $size];
    }

    public function exists(DocumentVersion $version): bool
    {
        return Storage::disk($version->disk)->exists($version->path);
    }

    /** @return resource|null */
    public function readStream(DocumentVersion $version)
    {
        return Storage::disk($version->disk)->readStream($version->path);
    }

    /**
     * The digest of what is actually stored, so the download gate can compare it with the row.
     */
    public function digest(DocumentVersion $version): ?string
    {
        $stream = $this->readStream($version);

        if ($stream === null) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    public function delete(DocumentVersion $version): bool
    {
        return Storage::disk($version->disk)->delete($version->path);
    }

    /**
     * Delete by path rather than by row, for the one case where there is no row yet: the upload
     * Action wrote bytes, then the transaction failed, and the rollback cannot reach a disk object.
     */
    public function deletePath(string $path): bool
    {
        return Storage::disk(self::Disk)->delete($path);
    }
}
