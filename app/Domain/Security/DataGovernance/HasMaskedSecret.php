<?php

declare(strict_types=1);

namespace App\Domain\Security\DataGovernance;

use App\Domain\Kernel\Identity\FieldGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The §9.5 column trio, applied by convention rather than by a cast: `<base>_encrypted` holds the
 * ciphertext, `<base>_masked` holds the display form, `key_version` records which key made it.
 *
 * Not an Eloquent cast, because one cast cannot own three columns and a silent
 * decrypt-on-read is precisely the thing that turns a field gate into a leak. Reading plaintext is
 * always a named call — {@see revealMaskedSecret()} — so the audit trail (§9.5) and the
 * re-authentication rule (§9.1) have a single place to hook, and `grep -r reveal` answers "what
 * can read bank numbers".
 *
 * @mixin Model
 */
trait HasMaskedSecret
{
    /**
     * @param  string  $base  column prefix, e.g. `number` for `number_encrypted`/`number_masked`
     */
    protected function writeMaskedSecret(string $base, ?string $plaintext): void
    {
        if ($plaintext === null || $plaintext === '') {
            $this->setAttribute("{$base}_encrypted", null);
            $this->setAttribute("{$base}_masked", null);

            return;
        }

        /** @var FieldCipher $cipher */
        $cipher = app(FieldCipher::class);

        $this->setAttribute("{$base}_encrypted", $cipher->encrypt($plaintext));
        $this->setAttribute("{$base}_masked", SecretMask::tail($plaintext));
        $this->setAttribute('key_version', $cipher->currentVersion());
    }

    /** The display form. Always safe to render, always works in lists, sorts and exports. */
    public function maskedSecret(string $base): string
    {
        $masked = $this->getAttribute("{$base}_masked");

        return is_string($masked) && $masked !== '' ? $masked : SecretMask::all();
    }

    /**
     * The only decrypt path. It asks the caller's gate first, so a model that reaches a screen
     * cannot reveal more than the screen is allowed to show — and a caller that has no gate to
     * name (a report, an export) has to say which one it is standing behind.
     */
    public function revealMaskedSecret(string $base, FieldGate $through): ?string
    {
        if (! Gate::allows($through->value)) {
            return null;
        }

        $payload = $this->getAttribute("{$base}_encrypted");

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $version = $this->getAttribute('key_version');

        /** @var FieldCipher $cipher */
        $cipher = app(FieldCipher::class);

        return $cipher->decrypt($payload, is_string($version) ? $version : $cipher->currentVersion());
    }
}
