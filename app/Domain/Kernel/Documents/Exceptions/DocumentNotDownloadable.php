<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Exceptions;

use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use RuntimeException;

/**
 * A download the document layer refuses (the gate in front of the private disk).
 *
 * This is the exception that makes the no-store response meaningful: the file is unreachable by
 * any other route, so a refusal here is a real refusal and not a screen that a curated URL walks
 * past.
 */
final class DocumentNotDownloadable extends RuntimeException
{
    public static function infected(DocumentVersion $version): self
    {
        return new self(sprintf(
            'Version %d of this document is flagged infected (%s) and will not be served. '
            .'Delete the version; do not download it and hope.',
            $version->version_seq,
            $version->scan_detail ?: 'no detail recorded',
        ));
    }

    /**
     * Unscanned bytes are served in development — where there is no ClamAV sidecar and refusing
     * would make the screen untestable — and refused in production, where the absence of a scan is
     * a decision, not an environment limitation. Q8 is unresolved; this is the honest
     * interim rule, and it is deliberately asymmetric so nobody mistakes dev behaviour for prod.
     */
    public static function unscanned(): self
    {
        return new self(
            'This version has not been scanned, and production does not serve unscanned bytes. '
            .'Either run the scanner or record the defence-in-depth exception (Q8).',
        );
    }

    public static function voided(Lifecycle $lifecycle): self
    {
        return new self("A {$lifecycle->value} document is not served: the lifecycle refuses it, not the file.");
    }

    public static function noCurrentVersion(): self
    {
        return new self('This document has no current version — every copy is a draft or the paper has no bytes yet.');
    }

    public static function missingFromDisk(DocumentVersion $version): self
    {
        return new self(sprintf(
            'Version %d is recorded on disk "%s" at "%s", but the file is not there. '
            .'That is a storage or backup problem, not a permissions one, and documents:verify-integrity reports it.',
            $version->version_seq,
            $version->disk,
            $version->path,
        ));
    }
}
