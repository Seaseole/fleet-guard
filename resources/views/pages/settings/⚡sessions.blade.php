<?php

use App\Domain\Security\Actions\RevokeEverySession;
use App\Domain\Security\Actions\RevokeSession;
use App\Domain\Security\Models\UserSession;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sessions')] class extends Component {
    /**
     * This device, as a hash. `#[Locked]` because it decides which row the "log out everywhere" button
     * leaves standing, and a client-settable value there would let a request keep its own session alive
     * while everyone else's were cut off.
     */
    #[Locked]
    public string $current = '';

    public function mount(): void
    {
        $this->current = UserSession::hash(session()->getId());
    }

    /** @return list<UserSession> */
    #[Computed]
    public function sessions(): array
    {
        return UserSession::activeFor((int) auth()->id());
    }

    public function revoke(string $hash, RevokeSession $revoke): void
    {
        $revoke(auth()->user(), $hash);

        unset($this->sessions);

        $this->dispatch('toast', message: __('That device is cut off. It stops working on its next request.'), kind: 'success');
    }

    public function revokeOthers(RevokeEverySession $revoke): void
    {
        $count = $revoke(auth()->user(), $this->current);

        unset($this->sessions);

        $this->dispatch('toast', message: trans_choice(
            'One other device has been cut off.|:count other devices have been cut off.',
            $count,
            ['count' => $count],
        ), kind: 'success');
    }

    public function revokeEverywhere(RevokeEverySession $revoke): void
    {
        $revoke(auth()->user(), null);

        Auth::guard('web')->logout();
        session()->flush();
        session()->regenerateToken();

        $this->redirect(route('login'));
    }

    /**
     * A name a person can recognise, from a string they cannot read. No package is involved: the four
     * families this fleet actually uses are four `str_contains` calls, and an unknown token is reported
     * as unknown rather than guessed at.
     */
    private function device(?string $agent): string
    {
        if ($agent === null || $agent === '') {
            return __('Unknown device');
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser === null && $os === null
            ? __('Unknown device')
            : trim(($os ?? '').' '.($browser ?? __('Unknown browser')));
    }
}; ?>

<section class="w-full space-y-6">
    @include('partials.settings-heading')

    <x-ui.text class="text-fg-ink-secondary">
        {{ __('Every device signed in as you, with the time it was last seen. Revoking one stops it working on its next request — it does not delete anything it has already saved.') }}
    </x-ui.text>

    <div class="flex flex-wrap gap-2">
        <x-ui.button
            variant="outline"
            wire:click="revokeOthers"
            wire:confirm="{{ __('Log out every other device? This one stays signed in.') }}"
            :disabled="count($this->sessions) <= 1"
        >{{ __('Log out other devices') }}</x-ui.button>

        <x-ui.button
            variant="danger"
            wire:click="revokeEverywhere"
            wire:confirm="{{ __('Log out everywhere, including this device? You will be signed in again with a password and your second factor.') }}"
        >{{ __('Log out everywhere') }}</x-ui.button>
    </div>

    <ul class="grid gap-3">
        @forelse ($this->sessions as $session)
            <li
                wire:key="session-{{ $session->id }}"
                @class([
                    'flex items-start justify-between gap-4 rounded-fg-lg border p-4',
                    'border-fg-line bg-fg-surface' => $session->id !== $current,
                    'border-accent bg-accent-soft' => $session->id === $current,
                ])
            >
                <div class="grid gap-1">
                    <div class="flex items-center gap-2">
                        <x-ui.heading size="xs">{{ $this->device($session->user_agent) }}</x-ui.heading>

                        @if ($session->id === $current)
                            <x-ui.badge tone="accent">{{ __('This device') }}</x-ui.badge>
                        @endif

                        @unless ($session->two_factor_ok)
                            <x-ui.badge tone="warning">{{ __('No second factor at sign-in') }}</x-ui.badge>
                        @endunless
                    </div>

                    <x-ui.text size="xs" color="muted">
                        {{ $session->ip_address ?? __('Unknown address') }}
                        · {{ __('Signed in :at', ['at' => $session->created_at->diffForHumans()]) }}
                        · {{ __('Last seen :at', ['at' => $session->last_seen_at->diffForHumans()]) }}
                        · {{ __('Ends :at', ['at' => $session->expires_at->diffForHumans()]) }}
                    </x-ui.text>
                </div>

                @if ($session->id !== $current)
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="revoke('{{ $session->id }}')"
                        wire:confirm="{{ __('Revoke this device? It will be signed out on its next request.') }}"
                    >{{ __('Revoke') }}</x-ui.button>
                @endif
            </li>
        @empty
            <x-ui.empty-state :title="__('No devices recorded yet')">
                {{ __('This list starts filling in the next time you sign in.') }}
            </x-ui.empty-state>
        @endforelse
    </ul>
</section>
