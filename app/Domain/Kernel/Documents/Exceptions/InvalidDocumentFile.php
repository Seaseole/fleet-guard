<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Exceptions;

use App\Domain\Kernel\Documents\DocumentType;
use RuntimeException;

/**
 * A file that never reaches the disk (§14.4's type validation, applied at the door rather than by a
 * DB error after the bytes are already stored).
 *
 * Every reason names the *sniffed* value, not the client's claim: when a screen shows a clerk why
 * their scan was refused, `the content is application/postscript, which is not accepted for a permit
 * (application/pdf, image/jpeg, image/png)` is actionable and `invalid file` is not.
 */
final class InvalidDocumentFile extends RuntimeException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function mimeNotAllowed(DocumentType $type, string $sniffed, array $allowed): self
    {
        return new self(sprintf(
            'The content is "%s", which is not accepted for a %s. Allowed: %s. '
            .'The type is read from the bytes, not the extension (§14.4).',
            $sniffed,
            strtolower($type->label()),
            implode(', ', $allowed),
        ));
    }

    public static function tooLarge(DocumentType $type, int $bytes, int $max): self
    {
        return new self(sprintf(
            'The file is %s MB; a %s is capped at %s MB (§14.4 size caps per type).',
            number_format($bytes / 1048576, 1),
            strtolower($type->label()),
            number_format($max / 1048576, 1),
        ));
    }

    public static function empty(): self
    {
        return new self('The file has no bytes. A zero-length version is a row that lists but never serves.');
    }

    public static function unreadable(string $reason): self
    {
        return new self("The uploaded file could not be read: {$reason}");
    }

    /**
     * The scanner refused it, so the bytes were never written.
     *
     * The detail is the signature, and it is worth carrying: "EICAR-Test-File" and a real find are
     * different conversations with the owner, and the second one is not started by an exception that
     * said only "infected".
     */
    public static function infected(?string $detail): self
    {
        return new self(
            'The scanner refused this file'.($detail === null ? '' : ": {$detail}").
            '. It was not written to the private disk (§14.4).',
        );
    }
}
