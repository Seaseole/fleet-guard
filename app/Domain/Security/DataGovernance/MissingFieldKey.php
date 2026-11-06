<?php

declare(strict_types=1);

namespace App\Domain\Security\DataGovernance;

use RuntimeException;

/**
 * Raised when a column that is promised encrypted cannot be read or written because its key is
 * not available. It is an error rather than a fallback: the alternative is a screen that quietly
 * shows decrypted data under the wrong key, or a write that stores plaintext with a `key_version`
 * that does not exist.
 */
final class MissingFieldKey extends RuntimeException
{
    public static function forVersion(string $version): self
    {
        return new self(
            "No DB_CRYPT_KEY is configured for key version '{$version}'. Set fleet.crypto.keys.{$version} in the environment; ".
            'this column never falls back to APP_KEY.'
        );
    }

    public static function malformed(string $cipher): self
    {
        return new self("DB_CRYPT_KEY is not decodable base64 for cipher '{$cipher}'.");
    }
}
