<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Security\Exceptions\InvitationAlreadyExists;
use App\Domain\Security\Mail\StaffInvitationMail;
use App\Domain\Security\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a staff account (§9.1: "The Owner invites by email with a role"). Registration is off in
 * `config/fortify.php`, so this Action, not a public form, is the only door into `users`.
 *
 * The token is returned once and stored only as a hash. Everything else about the invitation — who
 * asked for it, which role it grants, when it stops working — is on the row, because §9.5's audit
 * trail has to be able to answer "who made this person an Accountant" after the link is gone.
 */
final class InviteUser
{
    /**
     * @return array{invitation: Invitation, token: string}
     *
     * @throws InvitationAlreadyExists
     */
    public function __invoke(User $inviter, string $email, RoleKey $role): array
    {
        if (! in_array($role->value, RoleKey::invitable(), true)) {
            throw InvitationAlreadyExists::notInvitable($role);
        }

        $email = mb_strtolower(trim($email));

        // §9.1's "the Owner invites by email", bounded: five invites to one address in an hour is a
        // person clicking a button that already worked, and the sixth is the one that says so instead of
        // putting another link in front of someone who has not opened the first five.
        $limit = (array) config('fleet.identity.invite_throttle');
        $throttleKey = 'invite|'.$email.'|'.(request()->ip() ?? 'no-ip');

        if (RateLimiter::tooManyAttempts($throttleKey, (int) $limit['max'])) {
            throw ValidationException::withMessages([
                'email' => __('This address has already been invited :max times recently. Try again in :seconds seconds.', [
                    'max' => (int) $limit['max'],
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        $created = DB::transaction(function () use ($inviter, $email, $role): array {
            if (User::query()->where('email', $email)->exists()) {
                throw InvitationAlreadyExists::forUser($email);
            }

            // Only a *live* invitation blocks a new one. An expired row stays as history and the
            // Owner can send again; blocking on any past row would make a refused invite permanent.
            $pending = Invitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw InvitationAlreadyExists::pendingInvitation($email);
            }

            $token = Str::random(40);

            $invitation = new Invitation([
                'email' => $email,
                'role_key' => $role->value,
                'invited_by' => $inviter->id,
                'expires_at' => Carbon::now()->addHours((int) config('fleet.identity.invite_ttl_hours')),
            ]);

            // Set, never filled: `token_hash` is deliberately outside $fillable so no request can
            // plant one, and the plaintext token stays out of the database entirely — the same rule
            // §9.6 applies to kiosk tokens.
            $invitation->setAttribute('token_hash', hash('sha256', $token));
            $invitation->save();

            return ['invitation' => $invitation, 'token' => $token];
        });

        // Outside the transaction, deliberately: ADR-0002 §5's "no I/O inside the numbering
        // transaction" is a general rule, and an invitation email that escaped a rolled-back
        // transaction would be a live link to a row that does not exist.
        Mail::to($created['invitation']->email)->queue(
            new StaffInvitationMail($created['invitation'], $created['token'])
        );

        // Charged on the send rather than the attempt: a refused duplicate or a mistyped role costs
        // nobody an hour of quota, and the quota is about how many links land in one inbox.
        RateLimiter::hit($throttleKey, (int) $limit['decay_seconds']);

        return $created;
    }
}
