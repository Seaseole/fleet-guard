<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use finfo;

/**
 * The "type validation by MIME sniff *of the content*, not the extension", in one place.
 *
 * `finfo` reads magic bytes, so this is the answer the allowlist is compared against and the client's
 * `Content-Type` is only ever a claim checked against it. That is not ceremony: the extension and
 * the declared type are both written by the machine sending the file, and the bytes are the only
 * thing in the request that says what the file actually is.
 *
 * The corollary the config has to honour: an allowlist entry nobody can produce is worse than no
 * entry, because it reads as a rule while functioning as a refusal. Measured on PHP 8.4.27 with this
 * magic database (2026-10-26): `%PDF-1.4` → `application/pdf`, a JPEG SOI marker → `image/jpeg`, a
 * comma-headed CSV → `text/csv`, a single-column text file → `text/plain`, and a `.php` source file
 * → `text/x-php` — which no document type accepts, and that is the point of sniffing instead of
 * trusting the extension.
 */
final class ContentSniffer
{
    private readonly finfo $finfo;

    public function __construct(?finfo $finfo = null)
    {
        if (! extension_loaded('fileinfo')) {
            throw InvalidDocumentFile::unreadable('ext-fileinfo is not loaded, so content cannot be sniffed and cannot be honoured.');
        }

        $this->finfo = $finfo ?? new finfo(FILEINFO_MIME_TYPE);
    }

    public function sniff(string $absolutePath): string
    {
        $detected = $this->finfo->file($absolutePath);

        if (! is_string($detected) || $detected === '') {
            throw InvalidDocumentFile::unreadable("no magic bytes could be read from {$absolutePath}.");
        }

        return $detected;
    }
}
