<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use RuntimeException;

/**
 * The AES-256-GCM envelope, written with `ext-openssl` because the local PHP build has no
 * `sodium` and the design refuses to assume an extension it has not measured.
 *
 * **Framed, not one buffer.** The payload is split into fixed-size frames and each carries its own
 * nonce and authentication tag, so exporting a book that outgrows memory is a matter of reading the
 * next frame rather than of buying a bigger machine. GCM authenticates per message; framing gives
 * every frame a message of its own, and the index in the additional data makes reordering or
 * replaying a frame a failure rather than a silent edit.
 *
 * **The footer is the whole-file proof.** The last frame holds the plaintext length, its SHA-256 and
 * the export's creation time, and is itself authenticated. A truncated upload therefore has no
 * footer and cannot be mistaken for a complete copy — the failure mode this envelope exists for.
 *
 * The on-disk header (magic and frame size) is deliberately readable. It tells a person which tool
 * reads the object and lets `file`/a bucket console recognise it; it carries no key material.
 */
final class BackupCipher
{
    public const FILE_EXTENSION = 'fbk';

    private const MAGIC = "FGBK1\n";

    private const TYPE_DATA = 'D';

    private const TYPE_FOOTER = 'F';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    // strlen(MAGIC) + 4: the magic, then the big-endian frame size.
    private const HEADER_BYTES = 10;

    private readonly string $key;

    /** @var positive-int */
    private readonly int $chunkBytes;

    public function __construct(string $key, int $chunkBytes = 1_048_576)
    {
        if ($chunkBytes < 1_024) {
            throw new RuntimeException('A backup frame smaller than 1 KiB would spend more on tags than on data.');
        }

        $this->key = self::normalize($key);
        $this->chunkBytes = $chunkBytes;
    }

    /** Reads the configured key, or refuses before a single byte is written. */
    public static function fromConfig(?int $chunkBytes = null): self
    {
        $key = config('fleet.backup.key');

        if (! is_string($key) || trim($key) === '') {
            throw MissingBackupKey::notConfigured();
        }

        $configured = config('fleet.backup.chunk_bytes');

        return new self($key, $chunkBytes ?? (is_int($configured) ? $configured : 1_048_576));
    }

    /** A short non-secret label for the runbook: "is this object sealed with the key I think it is". */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->key), 0, 16);
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    public function encryptFile(string $plainPath, string $sealedPath): array
    {
        $in = fopen($plainPath, 'rb');
        $out = fopen($sealedPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Could not open {$plainPath} for sealing.");
        }

        $header = self::MAGIC.pack('N', $this->chunkBytes);
        fwrite($out, $header);

        $hash = hash_init('sha256');
        $bytes = 0;
        $index = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, $this->chunkBytes);

                if ($chunk === false) {
                    throw new RuntimeException('Reading the export payload failed mid-frame.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($hash, $chunk);
                $bytes += strlen($chunk);

                $this->writeFrame($out, $header, self::TYPE_DATA, $index++, $chunk);
            }

            $digest = hash_final($hash);

            $footer = json_encode([
                'format' => 1,
                'created_at' => now()->toIso8601String(),
                'sha256' => $digest,
                'bytes' => $bytes,
                'key_fingerprint' => $this->fingerprint(),
            ], JSON_THROW_ON_ERROR);

            $this->writeFrame($out, $header, self::TYPE_FOOTER, $index, $footer);
        } finally {
            fclose($in);
            fclose($out);
        }

        return ['sha256' => $digest, 'bytes' => $bytes];
    }

    /**
     * Decrypts the object and proves it is the file the exporter wrote.
     *
     * @return array{sha256: string, bytes: int, created_at: string}
     */
    public function decryptFile(string $sealedPath, string $plainPath): array
    {
        $in = fopen($sealedPath, 'rb');
        $out = fopen($plainPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Could not open {$sealedPath} for reading.");
        }

        try {
            $header = fread($in, self::HEADER_BYTES);

            if ($header === false || strlen($header) !== self::HEADER_BYTES || ! str_starts_with($header, self::MAGIC)) {
                throw BackupCorrupt::unreadable('the file does not begin with the FGBK1 header.');
            }

            /** @var array<int, int> $size */
            $size = unpack('N', substr($header, strlen(self::MAGIC)));
            $chunkBytes = $size[1] ?? 0;

            if ($chunkBytes < 1_024 || $chunkBytes > 64 * 1_048_576) {
                throw BackupCorrupt::unreadable("the frame size {$chunkBytes} is outside the accepted range.");
            }

            $hash = hash_init('sha256');
            $bytes = 0;
            $index = 0;
            $footer = null;

            while (! feof($in)) {
                $type = fread($in, 1);

                if ($type === false || $type === '') {
                    break;
                }

                if ($type !== self::TYPE_DATA && $type !== self::TYPE_FOOTER) {
                    throw BackupCorrupt::unreadable('an unknown frame type appeared at frame '.$index.'.');
                }

                $lengthBytes = fread($in, 4);

                if ($lengthBytes === false || strlen($lengthBytes) !== 4) {
                    throw BackupCorrupt::truncated($index);
                }

                /** @var array<int, int> $length */
                $length = unpack('N', $lengthBytes);
                $frameBytes = $length[1] ?? 0;

                if ($frameBytes < self::NONCE_BYTES + self::TAG_BYTES || $frameBytes > $chunkBytes + self::NONCE_BYTES + self::TAG_BYTES) {
                    throw BackupCorrupt::unreadable("frame {$index} declares {$frameBytes} bytes.");
                }

                $frame = $this->readExact($in, $frameBytes, $index);
                $plain = $this->open($header, $type, $index, $frame);

                if ($type === self::TYPE_FOOTER) {
                    $footer = $plain;

                    break;
                }

                hash_update($hash, $plain);
                $bytes += strlen($plain);

                if (fwrite($out, $plain) === false) {
                    throw new RuntimeException("Writing the restore payload failed at frame {$index}.");
                }

                $index++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($footer === null) {
            throw BackupCorrupt::truncated($index);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($footer, true);

        if (! is_array($decoded) || ! isset($decoded['sha256'], $decoded['bytes'])) {
            throw BackupCorrupt::unreadable('the sealed footer is not a manifest.');
        }

        /** @var array{sha256: string, bytes: int, created_at?: string, key_fingerprint?: string} $record */
        $record = $decoded;
        $actual = hash_final($hash);

        if ($actual !== $record['sha256'] || $bytes !== (int) $record['bytes']) {
            throw BackupCorrupt::digestMismatch($record['sha256'], $actual);
        }

        return [
            'sha256' => $record['sha256'],
            'bytes' => $bytes,
            'created_at' => $record['created_at'] ?? '',
        ];
    }

    /** @param resource $out */
    private function writeFrame($out, string $header, string $type, int $index, string $plain): void
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalData($header, $type, $index),
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Sealing frame {$index} failed: ".openssl_error_string());
        }

        $payload = $nonce.$ciphertext.$tag;

        if (fwrite($out, $type.pack('N', strlen($payload)).$payload) === false) {
            throw new RuntimeException("Writing sealed frame {$index} failed.");
        }
    }

    private function open(string $header, string $type, int $index, string $frame): string
    {
        $nonce = substr($frame, 0, self::NONCE_BYTES);
        $tag = substr($frame, -self::TAG_BYTES);
        $ciphertext = substr($frame, self::NONCE_BYTES, -self::TAG_BYTES);

        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalData($header, $type, $index),
        );

        if ($plain === false) {
            throw BackupCorrupt::frameFailed($index);
        }

        return $plain;
    }

    private function additionalData(string $header, string $type, int $index): string
    {
        // The header binds the frame size and the file's identity; the index binds the order. A frame
        // lifted from another export, or two frames swapped, then fails authentication instead of
        // quietly restoring somebody else's rows.
        return $header.$type.pack('N', $index);
    }

    /**
     * @param  resource  $in
     * @param  positive-int  $bytes
     */
    private function readExact($in, int $bytes, int $index): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            /** @var positive-int $remaining */
            $remaining = $bytes - strlen($buffer);
            $read = fread($in, $remaining);

            if ($read === false || $read === '') {
                throw BackupCorrupt::truncated($index);
            }

            $buffer .= $read;
        }

        return $buffer;
    }

    private static function normalize(string $key): string
    {
        $material = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($material === false) {
            throw MissingBackupKey::malformed();
        }

        if (strlen($material) !== 32) {
            throw MissingBackupKey::wrongLength(strlen($material));
        }

        return $material;
    }
}
