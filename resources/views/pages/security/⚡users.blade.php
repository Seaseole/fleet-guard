<?php

use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Domain\Security\Actions\InviteUser;
use App\Domain\Security\Exceptions\InvitationAlreadyExists;
use App\Models\User;
use App\Domain\Security\Models\Invitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users and roles')] class extends Component {
    use ConfirmsRecentPassword;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'dispatcher';

    /** Shown once, straight after the invite; the row only ever holds the hash. */
    public ?string $issuedLink = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter'], true)) {
            $this->resetPage();
            unset($this->staff);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'roleFilter']);
        $this->resetPage();
        unset($this->staff);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->roleFilter !== '';
    }

    public function invite(InviteUser $inviteUser): void
    {
        abort_unless(Gate::allows('users.manage'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        $validated = $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:191'],
            'inviteRole' => ['required', 'string', 'in:'.implode(',', RoleKey::invitable())],
        ]);

        try {
            $result = $inviteUser(
                auth()->user(),
                $validated['inviteEmail'],
                RoleKey::fromKey($validated['inviteRole']),
            );
        } catch (InvitationAlreadyExists $rejected) {
            $this->addError('inviteEmail', $rejected->getMessage());

            return;
        }

        $this->issuedLink = route('security.invitations.accept', ['token' => $result['token']]);
        $this->inviteEmail = '';

        $this->dispatch('toast', message: __('Invitation sent.'), kind: 'success');
    }

    public function changeRole(int $userId, string $role, ChangeUserRole $changeUserRole): void
    {
        if ($role === '') {
            return;
        }

        abort_unless(Gate::allows('users.manage'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        /** @var User $target */
        $target = User::query()->findOrFail($userId);

        abort_unless(Gate::allows('changeRole', $target), 403);

        $changeUserRole($target, RoleKey::fromKey($role));

        unset($this->staff);

        $this->dispatch('toast', message: __('Role updated.'), kind: 'success');
    }

    public function revoke(int $invitationId): void
    {
        abort_unless(Gate::allows('users.manage'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        /** @var Invitation $invitation */
        $invitation = Invitation::query()->findOrFail($invitationId);

        // Deleting the row is the revoke: a link whose hash no longer resolves is dead. An
        // invitation is not a posted financial record, so the no-delete rule does not reach it —
        // and its history belongs to the audit trail, not to this table.
        $invitation->delete();

        unset($this->pendingInvitations);

        $this->dispatch('toast', message: __('Invitation revoked.'), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function staff(): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->when($this->search !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where(fn ($or) => $or->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($this->roleFilter !== '', fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $this->roleFilter)))
            ->orderByRaw('case when id = ? then 0 else 1 end', [auth()->id()])
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return Collection<int, Invitation> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return Invitation::query()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();
    }
}; ?>

    <div class="grid gap-6">
        <header class="fg-page-header grid gap-1.5">
            <x-ui.heading size="xl" level="1">{{ __('Users and roles') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Staff accounts are invited. There is no sign-up form on this system.') }}
            </x-ui.text>
        </header>

        <x-ui.card
            :title="__('Staff accounts')"
            :description="__('A role is a job, not a pile of permissions. One role per person.')"
        >
            <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
                <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Name or email')" />

                <div class="grid gap-1.5">
                    <label for="role-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Role') }}</label>
                    <x-ui.select name="roleFilter" id="role-filter" wire:model.live="roleFilter">
                        <option value="">{{ __('All roles') }}</option>
                        @foreach (RoleKey::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>

            <x-ui.table :caption="__('Staff accounts')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Email') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Role') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Email verified') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->staff as $person)
                        <tr wire:key="user-{{ $person->id }}">
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $person->name }}</span>

                                @if ($person->id === auth()->id())
                                    <x-ui.badge tone="accent" size="xs" class="ms-2">{{ __('you') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td class="text-fg-ink-secondary">{{ $person->email }}</x-ui.table.td>

                            <x-ui.table.td>
                                @if ($person->roles->isEmpty())
                                    <x-ui.badge tone="warning" icon="exclamation-triangle">{{ __('no role') }}</x-ui.badge>
                                @else
                                    @foreach ($person->roles as $held)
                                        <x-ui.badge tone="neutral" icon="shield-check">
                                            {{ \App\Domain\Kernel\Identity\RoleKey::fromKey($held->name)->label() }}
                                        </x-ui.badge>
                                    @endforeach
                                @endif

                                @if (Gate::allows('users.manage') && $person->id !== auth()->id())
                                    <x-ui.select
                                        wire:change="changeRole({{ $person->id }}, $event.target.value)"
                                        aria-label="{{ __('Change role') }}"
                                    >
                                        <option value="">{{ __('change role…') }}</option>

                                        @foreach (\App\Domain\Kernel\Identity\RoleKey::cases() as $option)
                                            <option value="{{ $option->value }}" @selected($person->roles->pluck('name')->contains($option->value))>
                                                {{ $option->label() }}
                                            </option>
                                        @endforeach
                                    </x-ui.select>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td>
                                @if ($person->email_verified_at)
                                    <x-ui.badge tone="success" icon="check">{{ __('yes') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="neutral">{{ __('not yet') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <x-ui.table.td colspan="4">
                                <x-ui.empty-state
                                    icon="users"
                                    :title="$this->hasFilters() ? __('No staff match these filters') : __('No staff accounts yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or role filter to see the rest of the register.')
                                        : __('Invite the first person from the form below. The Owner account is created during first-run setup.')"
                                />
                            </x-ui.table.td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->staff->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->staff->links() }}
                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total staff accounts.', [
                            'first' => $this->staff->firstItem(),
                            'last' => $this->staff->lastItem(),
                            'total' => $this->staff->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        </x-ui.card>

        @can('users.manage')
            <x-ui.card
                :title="__('Invite a staff account')"
                :description="__('The link works once and expires. Nothing is created until it is accepted.')"
            >
                <form class="grid gap-4" wire:submit="invite">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input wire:model="inviteEmail" name="inviteEmail" type="email" :label="__('Email address')" required />

                        <div class="grid gap-1.5">
                            <label for="inviteRole" class="text-xs font-medium text-fg-ink-secondary">{{ __('Role') }}</label>

                            <x-ui.select name="inviteRole" id="inviteRole" wire:model.live="inviteRole">
                                @foreach (\App\Domain\Kernel\Identity\RoleKey::invitable() as $key)
                                    <option value="{{ $key }}">{{ \App\Domain\Kernel\Identity\RoleKey::fromKey($key)->label() }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.text size="xs" color="muted">{{ \App\Domain\Kernel\Identity\RoleKey::fromKey($inviteRole)->description() }}</x-ui.text>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-ui.button variant="primary" type="submit" wire:target="invite" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="invite">{{ __('Send invitation') }}</span>
                            <span wire:loading wire:target="invite">{{ __('Sending…') }}</span>
                        </x-ui.button>
                        <x-ui.text size="xs" color="muted">{{ __('Sends one email; nothing is created until the link is used.') }}</x-ui.text>
                    </div>
                </form>

                @if ($issuedLink)
                    <div class="mt-4">
                        <x-ui.alert tone="info" :title="__('Copy this link to the invitee')">
                            <p class="break-all font-mono text-xs">{{ $issuedLink }}</p>

                            <x-ui.text size="xs" color="muted" class="mt-2">
                                {{ __('Shown once because only the hash is stored. Email delivery is the normal path; this is the fallback when it fails.') }}
                            </x-ui.text>
                        </x-ui.alert>
                    </div>
                @endif

                @if ($this->pendingInvitations->isNotEmpty())
                    <div class="mt-6 grid gap-2">
                        <x-ui.subheading>{{ __('Live invitations') }}</x-ui.subheading>

                        @foreach ($this->pendingInvitations as $invitation)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-fg-line pb-2 text-sm last:border-0">
                                <span class="text-fg-ink">{{ $invitation->email }}</span>

                                <span class="flex items-center gap-2">
                                    <x-ui.badge tone="neutral">{{ $invitation->role()->label() }}</x-ui.badge>
                                    <x-ui.text size="xs" color="muted">{{ $invitation->expires_at->diffForHumans() }}</x-ui.text>
                                    <x-ui.button variant="ghost" size="sm" wire:click="revoke({{ $invitation->id }})">{{ __('Revoke') }}</x-ui.button>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endcan
    </div>
