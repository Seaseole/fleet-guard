<?php

declare(strict_types=1);

namespace App\Domain\Security\Mail;

use App\Domain\Security\Actions\InviteUser;
use App\Domain\Security\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation email (§9.1). Queued with `afterCommit` by {@see InviteUser()}
 * so a rolled-back invite can never have been delivered.
 *
 * The token is carried in the payload of this object only. It is not on the model, so it cannot be
 * picked up by an audit diff (§9.5), a queue retry that re-reads the row, or a `dd()` of the
 * invitation — the row stores its hash and the link is rebuilt from here.
 */
final class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        private readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :company', [
                'company' => $this->invitation->company->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.security.invitation',
            with: [
                'invitation' => $this->invitation,
                'companyName' => $this->invitation->company->name,
                'acceptUrl' => route('security.invitations.accept', ['token' => $this->token]),
                'expiresAt' => $this->invitation->expires_at,
                'roleLabel' => $this->invitation->role()->label(),
                'invitedBy' => $this->invitation->inviter?->name,
            ],
        );
    }
}
