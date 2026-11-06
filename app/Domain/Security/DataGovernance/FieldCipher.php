<?php

declare(strict_types=1);

namespace App\Domain\Security\DataGovernance;

use Illuminate\Encryption\Encrypter;

/**
 * The column-level key, separate from `APP_KEY`.
 *
 * Versioned on purpose: rotation becomes `keys:reencrypt` reading `key_version` per row instead of
 * a data-loss event, and an old row stays readable while new writes use the current key.
 *
 * Refuses to fall back to `APP_KEY`. A column this design promises is encrypted must not silently
 * become "encrypted with the app key" because a deploy forgot an environment variable — the value
 * of the separate key is that it can be escrowed and rotated independently.
 */
final class FieldCipher
{
    /** @var array<string, string> */
    private array $keys;

    private string $currentVersion;

    private string $cipher;

    public function __construct()
    {
        /** @var array{current_version: string, cipher: string, keys: array<string, string|null>} $config */
        $config = config('fleet.crypto');

        $this->currentVersion = $config['current_version'];
        $this->cipher = $config['cipher'];
        $this->keys = array_filter($config['keys'], fn (?string $key): bool => filled($key));
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    /**
     * Whether the configured key ring can read a row stamped with this version. Rotation asks this
     * before writing anything: discovering a version nothing can decrypt halfway through a run is
     * the difference between "the job stopped" and "a column is now unreadable".
     */
    public function supports(string $version): bool
    {
        return isset($this->keys[$version]);
    }

    /** The payload is safe to store in a `text` column; it is base64 of a JSON envelope. */
    public function encrypt(string $plaintext): string
    {
        return $this->encrypter($this->currentVersion)->encryptString($plaintext);
    }

    /**
     * Decrypts with the key version recorded on the row, not the current one — that is the
     * difference between a rotatable key and a broken dataset.
     */
    public function decrypt(string $payload, string $keyVersion): string
    {
        return $this->encrypter($keyVersion)->decryptString($payload);
    }

    private function encrypter(string $version): Encrypter
    {
        return new Encrypter($this->normalize($this->keyFor($version)), $this->cipher);
    }

    /** @throws MissingFieldKey */
    private function keyFor(string $version): string
    {
        $key = $this->keys[$version] ?? null;

        if ($key === null) {
            throw MissingFieldKey::forVersion($version);
        }

        return $key;
    }

    /**
     * Accepts either a base64-encoded key (`base64:…`, the shape `APP_KEY` uses and what
     * `key:generate` writes) or raw key material of the correct length for the cipher.
     *
     * @throws MissingFieldKey
     */
    private function normalize(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false) {
            throw MissingFieldKey::malformed($this->cipher);
        }

        return $decoded;
    }
}
