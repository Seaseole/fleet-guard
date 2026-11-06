<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use App\Domain\Kernel\Identity\RoleKey;
use RuntimeException;

/**
 * The three reasons an invitation cannot be sent. Each is a different message on screen, and the
 * first one is deliberately not answered with detail: "does this address already work for you?" is
 * a question an Owner can ask, but not one an invite form should leak into a search endpoint.
 */
final class InvitationAlreadyExists extends RuntimeException
{
    public static function forUser(string $email): self
    {
        return new self("A staff account already uses {$email}.");
    }

    public static function pendingInvitation(string $email): self
    {
        return new self("There is already a live invitation for {$email}. Revoke it to send a new one.");
    }

    public static function notInvitable(RoleKey $role): self
    {
        return new self("The {$role->label()} role is not granted by invitation — it is created by the first-run setup.");
    }
}
