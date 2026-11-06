<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use RuntimeException;

/**
 * The export key is missing or malformed.
 *
 * A hard failure, never a plaintext fallback and never `DB_CRYPT_KEY`: an export that quietly leaves
 * the building unencrypted because an environment variable was left out of a deploy is the exact
 * accident the encryption exists to prevent. The message names the variable and the shape the key
 * must have, because the person reading it is mid-incident.
 */
final class MissingBackupKey extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'No off-site backup key is configured. Set FLEET_BACKUP_KEY (fleet.backup.key) to a '
            .'base64-encoded 32-byte key — generate one with `php artisan key:generate --show` and '
            .'escrow it off-box. The export refuses to write plaintext.',
        );
    }

    public static function wrongLength(int $bytes): self
    {
        return new self(sprintf(
            'FLEET_BACKUP_KEY decodes to %d bytes; AES-256-GCM needs 32. Keys of any other length are '
            .'refused rather than truncated or padded, because a shortened key is a weaker key that '
            .'still appears to work.',
            $bytes,
        ));
    }

    public static function malformed(): self
    {
        return new self('FLEET_BACKUP_KEY is not a base64 `base64:…` value or 32 bytes of raw key material.');
    }
}
