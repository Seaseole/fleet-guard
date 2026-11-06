<?php

use App\Domain\Security\Actions\AcceptInvitation;
use App\Domain\Security\Exceptions\InvitationNotAcceptable;
use App\Domain\Security\Models\Invitation;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/*
 * The only unauthenticated write screen in the application. Public registration is removed,
 * so this is how a person becomes a staff account: an invitation link that works once, carries the
 * role, and proves the address by having been delivered to it.
 *
 * The password is validated with the same rule Fortify applies elsewhere, so an invited Accountant
 * does not arrive with `password123` and then have to be asked to fix it before the mandatory-MFA
 * rule can be enforced.
 *
 * No session is started here. The account is created and the person is sent to the normal login
 * screen, which is where two-factor enrolment, session recording and the `verified` middleware all
 * live — a second sign-in path would be a second implementation of all three.
 */
new #[Layout('layouts::auth')] #[Title('Accept your invitation')] class extends \Livewire\Component {
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public ?string $failed = null;

    public ?string $invitationEmail = null;

    public ?string $invitationRole = null;

    public function mount(): void
    {
        $this->look();
    }

    public function accept(AcceptInvitation $accept): void
    {
        $this->look();

        if ($this->invitationEmail === null) {
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        try {
            $accept($this->token, $validated['name'], $validated['password']);
        } catch (InvitationNotAcceptable $exception) {
            $this->failed = $exception->getMessage();

            return;
        }

        $this->redirectRoute('login', navigate: true);

        session()->flash('status', __('Invitation accepted — log in with the password you just chose.'));
    }

    /**
     * Resolves the link for display, and reports why it cannot be used. Unknown, expired and used
     * tokens are all shown the same way on purpose (this page must not become a way to
     * enumerate who has been invited).
     */
    private function look(): void
    {
        if ($this->token === '' || $this->invitationEmail !== null) {
            return;
        }

        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->withoutCompanyScope()
            ->where('token_hash', hash('sha256', $this->token))
            ->first();

        if ($invitation === null) {
            $this->failed = InvitationNotAcceptable::unknownToken()->getMessage();

            return;
        }

        if ($invitation->accepted_at !== null) {
            $this->failed = InvitationNotAcceptable::accepted()->getMessage();

            return;
        }

        if ($invitation->isExpired()) {
            $this->failed = InvitationNotAcceptable::expired($invitation->email)->getMessage();

            return;
        }

        $this->invitationEmail = $invitation->email;
        $this->invitationRole = $invitation->role()->label();
    }
}; ?>

    <div class="grid gap-6">
        @if ($invitationEmail)
            <x-auth-header
                :title="__('You have been invited')"
                :description="__('Set a password to create your :role account.', ['role' => $invitationRole])"
            />
        @else
            <x-auth-header
                :title="__('This invitation cannot be used')"
                :description="__('Ask the Owner to send a new link.')"
            />
        @endif

        @if ($failed)
            <x-ui.alert tone="warning" :title="__('Invitation not accepted')">{{ $failed }}</x-ui.alert>
        @endif

        @if ($invitationEmail)
            <form class="grid gap-5" wire:submit="accept">
                <x-ui.input :label="__('Invited address')" :value="$invitationEmail" disabled />

                <x-ui.input wire:model="name" name="name" :label="__('Your name')" required autofocus autocomplete="name" />

                <x-ui.input
                    wire:model="password"
                    name="password"
                    type="password"
                    :label="__('Password')"
                    required
                    autocomplete="new-password"
                    viewable
                    :hint="__('Use a long, random one — you will be asked to set up two-factor authentication next.')"
                />

                <x-ui.button variant="primary" type="submit" class="w-full">{{ __('Create my account') }}</x-ui.button>
            </form>
        @endif

        <div class="text-center">
            <x-ui.link :href="route('login')" wire:navigate>{{ __('Back to log in') }}</x-ui.link>
        </div>
    </div>
