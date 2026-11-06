<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use RuntimeException;

/**
 * The sealed export is not the file the exporter wrote: a frame failed authentication, the stream
 * ended before its footer, or the plaintext digest did not match.
 *
 * Distinguishing *which* check failed matters during a drill — a truncated upload and a deliberate
 * edit are different incidents — but both must stop the restore. A backup whose integrity cannot be
 * proven is not a backup; restoring it would be manufacturing history from whatever was left.
 */
final class BackupCorrupt extends RuntimeException
{
    public static function unreadable(string $why): self
    {
        return new self("The off-site export could not be read: {$why}");
    }

    public static function frameFailed(int $index): self
    {
        return new self(
            "Frame {$index} of the export failed AES-256-GCM authentication. The object was altered, "
            .'truncated or sealed with a different key; it is not restorable.',
        );
    }

    public static function truncated(int $index): self
    {
        return new self(
            "The export ended at frame {$index} without its sealed footer. A copy that was cut short "
            .'during upload reads exactly like this.',
        );
    }

    public static function digestMismatch(string $expected, string $actual): self
    {
        return new self("The decrypted export hashes to {$actual}, but its sealed footer records {$expected}.");
    }
}
