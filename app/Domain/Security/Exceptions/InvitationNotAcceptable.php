<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use RuntimeException;

/**
 * Why an invitation link refused to work, in the three shapes it can fail. The distinction matters
 * on screen: "expired" tells the invitee to ask again, "already used" tells them the account exists
 * and they should log in, and "wrong address" tells them they are signed into a different mailbox.
 */
final class InvitationNotAcceptable extends RuntimeException
{
    public static function expired(string $email): self
    {
        return new self("This invitation for {$email} has expired. Ask the Owner to send a new one.");
    }

    public static function accepted(): self
    {
        return new self('This invitation has already been used.');
    }

    public static function wrongAddress(string $expected): self
    {
        return new self("This link must be accepted while signed in as {$expected}.");
    }

    public static function unknownToken(): self
    {
        // Deliberately vague to whoever is holding the link, and the same message for "never
        // existed" and "revoked": an invitation error page that distinguishes the two is a way to
        // enumerate who has been invited.
        return new self('This invitation is not valid.');
    }

    /**
     * A deploy fault, not a user fault: the invitation names a role that this company's role set
     * does not contain (the first-run setup has not run, or the catalogue changed under an
     * existing invite). It reaches the log, and the invitee is told the link needs re-sending.
     */
    public static function roleSetMissing(string $roleKey, int $companyId): self
    {
        return new self("Role '{$roleKey}' is not seeded for company {$companyId} — run php artisan db:seed --class=RolesSeeder.");
    }
}
