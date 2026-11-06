<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Exceptions;

use App\Domain\Kernel\Documents\DocumentType;
use RuntimeException;

/**
 * A document type the enum offers but `fleet.documents.types` does not describe.
 *
 * Thrown rather than defaulted: a type with no allowlist would otherwise have to pick one, and the
 * pick that costs nothing at the time — "accept anything under 10 MB" — is the one that lets an
 * executable be filed as a licence. Adding a case to `DocumentType` is meant to be accompanied by
 * its config entry, and this exception is what enforces that pairing.
 */
final class UnconfiguredDocumentType extends RuntimeException
{
    public static function for(DocumentType $type): self
    {
        return new self(
            "Document type \"{$type->value}\" has no entry under fleet.documents.types, so its allowed ".
            'MIME types and size cap are unknown and the upload cannot be judged. Add the config entry — '.
            'refusing the upload is the safe default.',
        );
    }
}
