<?php

declare(strict_types=1);

namespace App\Domain\Security\DataGovernance;

/**
 * the `*_masked` column, written next to the ciphertext rather than derived at render time.
 *
 * That is what lets search, sorting and reports keep working: a column whose only form is
 * ciphertext cannot be ordered, grouped or matched against, so the display form has to exist in
 * clear. It is also why the mask rule is *here* rather than in a Blade file — a mask produced at
 * render time and a mask stored in the column would eventually disagree.
 */
final class SecretMask
{
    private const BULLET = '•';

    /** The fixed prefix of `••••4521` shape. */
    private const BULLETS = '••••';

    /** How many characters stay readable: the tail a human dictates over the phone. */
    private const VISIBLE = 4;

    /**
     * Keeps the last four and replaces the rest with a **fixed** four-bullet prefix —
     * example, `••••4521`. A prefix that repeated once per hidden character would reveal the length
     * of the account number, which is itself information about the institution, and the mask column
     * is readable by every role with `bank.view`.
     */
    public static function tail(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return self::all();
        }

        $characters = preg_replace('/\s+/', '', $value) ?? $value;
        $length = mb_strlen($characters);

        if ($length <= self::VISIBLE) {
            return self::all($length);
        }

        return self::BULLETS.mb_substr($characters, -self::VISIBLE);
    }

    /** A mask that reveals nothing, for a field the viewer is not entitled to see at all. */
    public static function all(int $length = 8): string
    {
        return str_repeat(self::BULLET, max(1, $length));
    }
}
