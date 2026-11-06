<?php

use App\Domain\Kernel\Alerts\Actions\TransitionAlert;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\Exceptions\AlertTransitionRefused;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The alert register — one screen for "what needs attention", because the `Alert` row is the truth and the bell and the email are copies of it. A screen that recomputed
 * urgency at render would be a third copy.
 *
 * Rows are ordered by severity first and *age of the condition* second. DESIGN-SYSTEM's first rule is
 * action-first: a lapsed roadworthiness certificate has to be above a service due in three weeks, and a
 * critical alert that has been open for eleven days must not fall below one raised two minutes ago —
 * "still not dealt with" is the fact the Owner acts on, and `last_seen_at` says it.
 *
 * The three exits live here rather than behind a detail page, and each asks for its reason inline. A
 * decision you have to navigate away to make is a decision you make without writing anything down —
 * the record is lost.
 *
 * Mutes need a date, and the form refuses an empty one before the Action sees it: forbids a
 * permanent mute, and a screen that let someone press "mute" with no end would either fail with a
 * database error or, worse, invent a default expiry nobody chose.
 */
new #[Title('Alerts')] class extends Component {
    use WithPagination;

    #[Url]
    public string $filter = 'live';

    public string $mutingId = '';

    public string $muteReason = '';

    public string $muteUntil = '';

    public string $resolvingId = '';

    public string $resolveReason = '';

    public ?string $error = null;

    #[Computed]
    public function alerts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // The "Decided by" column names a seat for every non-open row, so all three actor links are
        // loaded with the page: `preventLazyLoading()` is on outside production, and a resolved or
        // muted row would otherwise fail the render instead of naming who decided it.
        return Alert::query()
            ->with(['acknowledgedBy', 'mutedBy', 'resolvedBy'])
            ->when($this->filter === 'live', fn ($q) => $q->live())
            ->when($this->filter === 'resolved', fn ($q) => $q->where('state', AlertState::Resolved->value))
            ->orderByRaw('case severity when \'critical\' then 0 when \'warning\' then 1 else 2 end')
            ->orderByDesc('last_seen_at')
            ->orderBy('occurred_on')
            ->paginate(50);
    }

    /**
     * @return array{open: int, critical: int, muted: int}
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'open' => Alert::query()->live()->count(),
            'critical' => Alert::query()->live()->where('severity', AlertSeverity::Critical->value)->count(),
            'muted' => Alert::query()->live()->where('state', AlertState::Muted->value)->count(),
        ];
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['live', 'resolved', 'all'], true) ? $filter : 'live';

        $this->resetPage();
        unset($this->alerts);
    }

    public function acknowledge(string $id): void
    {
        Gate::authorize('alerts.acknowledge');

        $this->applyTransition($id, fn (Alert $alert, TransitionAlert $transition): Alert => $transition->handle(
            $alert,
            AlertState::Acknowledged,
            byUserId: (int) auth()->id(),
        ), __('Acknowledged. It stays on the register until it is resolved — seeing it is not fixing it.'));
    }

    public function startMuting(string $id): void
    {
        $this->reset(['error', 'resolvingId', 'resolveReason']);

        if ($this->mutingId !== $id) {
            $this->reset(['muteReason', 'muteUntil']);
        }

        if ($this->muteUntil === '') {
            $this->muteUntil = Carbon::now(CurrentCompany::timezone())->addDays(7)->toDateString();
        }

        $this->mutingId = $id;
    }

    public function stopMuting(): void
    {
        $this->reset(['mutingId', 'muteReason', 'muteUntil', 'error']);
    }

    public function mute(): void
    {
        Gate::authorize('alerts.acknowledge');

        $alert = $this->muting();

        if ($alert === null) {
            $this->error = __('That alert is no longer on the register.');

            return;
        }

        $this->validate([
            'muteReason' => 'required|string|min:3|max:255',
            'muteUntil' => 'required|date',
        ], [
            'muteReason.required' => __('A mute needs a reason. A mute without one is a resolve nobody wrote down.'),
            'muteUntil.required' => __('A mute needs an end. A mute that never ends is a permanent silence, which forbids.'),
        ]);

        $id = $alert->id;

        $applied = $this->applyTransition($id, fn (Alert $a, TransitionAlert $transition): Alert => $transition->handle(
            $a,
            AlertState::Muted,
            $this->muteReason,
            Carbon::parse($this->muteUntil)->endOfDay(),
            byUserId: (int) auth()->id(),
        ), __('Muted until :date. The sweep reopens it, whatever else is happening that night.', [
            'date' => Carbon::parse($this->muteUntil)->toDateString(),
        ]));

        if ($applied) {
            $this->stopMuting();
        }
    }

    public function startResolving(string $id): void
    {
        $this->reset(['error', 'mutingId', 'muteReason', 'muteUntil']);

        if ($this->resolvingId !== $id) {
            $this->reset('resolveReason');
        }

        $this->resolvingId = $id;
    }

    public function stopResolving(): void
    {
        $this->reset(['resolvingId', 'resolveReason', 'error']);
    }

    public function resolve(): void
    {
        // Not `alerts.acknowledge`: this is the transition the engine cannot undo
        // (`alerts_resolved_is_final`), so it is the one write on the register the roles that merely
        // receive reminders do not get.
        Gate::authorize('alerts.resolve');

        $alert = $this->resolving();

        if ($alert === null) {
            $this->error = __('That alert is no longer on the register.');

            return;
        }

        $this->validate([
            'resolveReason' => 'required|string|min:3|max:255',
        ], [
            'resolveReason.required' => __('Saying how it was dealt with is the point of resolving it.'),
        ]);

        $id = $alert->id;

        $applied = $this->applyTransition($id, fn (Alert $a, TransitionAlert $transition): Alert => $transition->handle(
            $a,
            AlertState::Resolved,
            $this->resolveReason,
            byUserId: (int) auth()->id(),
        ), __('Resolved. If the condition comes back, the next run raises a new alert — this row stays as it is.'));

        if ($applied) {
            $this->stopResolving();
        }
    }

    public function muting(): ?Alert
    {
        return $this->mutingId === '' ? null : Alert::query()->find($this->mutingId);
    }

    public function resolving(): ?Alert
    {
        return $this->resolvingId === '' ? null : Alert::query()->find($this->resolvingId);
    }

    /**
     * One path for all three exits, so a refusal reads the same whichever button was pressed.
     *
     * The exception's own sentence is shown rather than a generic "failed": {@see
     * AlertTransitionRefused::between()} names the state the machine does not go to and the states it
     * does, which is the difference between an operator recovering and an operator calling.
     *
     * Named `applyTransition`, not `transition`: Livewire's own SupportTransitions feature mixes a
     * **public** `transition()` into every component, and a private method of the same name is a fatal
     * at compile time — the screen does not render, it 500s before the first query. `/alerts` was
     * asserted reachable by a route and a middleware check in the verifier, both of which were true
     * while the page was never loadable at all.
     */
    private function applyTransition(string $id, callable $handle, string $flourish): bool
    {
        $this->error = null;

        $alert = Alert::query()->find($id);

        if ($alert === null) {
            $this->error = __('That alert is no longer on the register.');

            return false;
        }

        try {
            $handle($alert, app(TransitionAlert::class));
        } catch (AlertTransitionRefused $refused) {
            $this->error = $refused->getMessage();

            return false;
        }

        unset($this->alerts, $this->counts);

        $this->dispatch('toast', message: $flourish, kind: 'success');

        return true;
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <div class="flex items-start justify-between gap-4">
            <div class="grid gap-1">
                <x-ui.heading size="xl" level="1">{{ __('Alerts') }}</x-ui.heading>
                <x-ui.text>
                    {{ __('One register for everything that needs attention. The bell, the email and this screen are copies of these rows, not three separate systems.') }}
                </x-ui.text>
            </div>

            <div class="flex min-w-0 flex-wrap justify-end gap-2">
                <x-ui.badge :tone="$this->counts['critical'] > 0 ? 'danger' : 'neutral'" icon="exclamation-triangle">
                    {{ __(':count critical', ['count' => $this->counts['critical']]) }}
                </x-ui.badge>
                <x-ui.badge tone="neutral" icon="clock">{{ __(':count open', ['count' => $this->counts['open']]) }}</x-ui.badge>

                @if ($this->counts['muted'] > 0)
                    {{-- `eye-slash`, not a speaker glyph: the registry is a closed list built from the
                         Flux stubs that slice 2 removed, so an unregistered name is not a missing icon —
                         `x-ui/icon.blade.php` throws, and this badge rendered only when a mute exists,
                         which is the one state no screen-only check had ever reached. --}}
                    <x-ui.badge tone="warning" icon="eye-slash">{{ __(':count muted', ['count' => $this->counts['muted']]) }}</x-ui.badge>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-1 pt-2">
            @foreach (['live' => __('Needs attention'), 'resolved' => __('Resolved'), 'all' => __('Everything')] as $key => $label)
                <x-ui.button
                    variant="ghost"
                    size="sm"
                    wire:click="setFilter('{{ $key }}')"
                    :class="$filter === $key ? 'bg-accent-soft font-semibold text-accent-ink' : ''"
                    :aria-pressed="$filter === $key"
                >{{ $label }}</x-ui.button>
            @endforeach
        </div>
    </header>

    @if ($error !== null)
        <x-ui.alert tone="danger" :title="__('That move was refused')">{{ $error }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Register')">
        <x-ui.table :caption="__('Alerts')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Alert') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Severity') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Condition from') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Decided by') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->alerts as $alert)
                    <tr wire:key="alert-{{ $alert->id }}">
                        <x-ui.table.td class="max-w-md">
                            <span class="font-medium text-fg-ink">{{ $alert->title() }}</span>
                            <div class="text-xs text-fg-ink-muted">
                                {{ $alert->type->label() }}
                                @if (($alert->payload['check'] ?? null) !== null)
                                    · {{ $alert->payload['check'] }}
                                @endif
                                {{-- `notify_count` counts deliveries, not sightings — cooldown
                                     question is "how many times did this go out", and the sighting clock
                                     is `last_seen_at`, two cells to the right. Zero is said out loud and
                                     in red rather than rounded up to one: an alert that reached nobody is
                                     the defect names, and the register is where it stops being
                                     invisible. --}}
                                ·
                                @if ($alert->notify_count === 0)
                                    <span class="text-danger">{{ __('told nobody yet') }}</span>
                                @elseif ($alert->notify_count === 1)
                                    {{ __('told once') }}
                                @else
                                    {{ __('told :count times', ['count' => $alert->notify_count]) }}
                                @endif
                            </div>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$alert->severity->tone()">{{ $alert->severity->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$alert->state->tone()">{{ $alert->state->label() }}</x-ui.badge>

                            @if ($alert->state === \App\Domain\Kernel\Alerts\AlertState::Muted && $alert->muted_until)
                                <div class="text-xs text-fg-ink-muted">
                                    {{ __('until :date', ['date' => $alert->muted_until->format('d M Y')]) }}
                                </div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td nowrap class="text-fg-ink-secondary">
                            <span title="{{ $alert->occurred_on->toDateString() }}">{{ $alert->occurred_on->format('d M Y') }}</span>
                            <div class="text-xs text-fg-ink-muted">{{ $alert->last_seen_at->diffForHumans() }}</div>
                        </x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary text-xs">
                            @if ($alert->state === \App\Domain\Kernel\Alerts\AlertState::Resolved)
                                {{ $alert->resolvedBy?->name ?? __('the scheduler') }}
                                <div class="text-fg-ink-muted">{{ Str::limit((string) $alert->resolve_reason, 48) }}</div>
                            @elseif ($alert->state === \App\Domain\Kernel\Alerts\AlertState::Muted)
                                {{ $alert->mutedBy?->name ?? '—' }}
                                <div class="text-fg-ink-muted">{{ Str::limit((string) $alert->mute_reason, 48) }}</div>
                            @elseif ($alert->state === \App\Domain\Kernel\Alerts\AlertState::Acknowledged)
                                {{ $alert->acknowledgedBy?->name ?? '—' }}
                            @else
                                <span class="text-fg-ink-muted">{{ __('nobody yet') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @can('alerts.acknowledge')
                                @if ($alert->state === \App\Domain\Kernel\Alerts\AlertState::Open)
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="acknowledge('{{ $alert->id }}')"
                                        wire:target="acknowledge('{{ $alert->id }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        <span wire:loading.remove wire:target="acknowledge('{{ $alert->id }}')">{{ __('Acknowledge') }}</span>
                                        <span wire:loading wire:target="acknowledge('{{ $alert->id }}')">{{ __('Working…') }}</span>
                                    </x-ui.button>
                                @endif

                                @if ($alert->state->canTransitionTo(\App\Domain\Kernel\Alerts\AlertState::Muted))
                                    <x-ui.button variant="ghost" size="sm" wire:click="startMuting('{{ $alert->id }}')">
                                        {{ __('Mute') }}
                                    </x-ui.button>
                                @endif
                            @endcan

                            @can('alerts.resolve')
                                @if ($alert->state->canTransitionTo(\App\Domain\Kernel\Alerts\AlertState::Resolved))
                                    <x-ui.button variant="ghost" size="sm" wire:click="startResolving('{{ $alert->id }}')">
                                        {{ __('Resolve') }}
                                    </x-ui.button>
                                @endif
                            @endcan
                        </x-ui.table.td>
                    </tr>

                    @if ($mutingId === (string) $alert->id)
                        <tr>
                            <x-ui.table.td colspan="6" class="bg-fg-surface-sunk">
                                <div class="grid gap-3">
                                    <x-ui.text size="xs">{{ __('Muting is a decision to stop being told about this for a while. both halves mandatory.') }}</x-ui.text>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <x-ui.input
                                            name="muteUntil"
                                            wire:model="muteUntil"
                                            type="date"
                                            :label="__('Mute until')"
                                            :hint="__('The nightly sweep reopens it after this date; a mute with no end is not allowed.')"
                                        />
                                        <x-ui.input
                                            name="muteReason"
                                            wire:model="muteReason"
                                            :label="__('Why')"
                                            :hint="__('e.g. the vehicle is off the road until the November audit.')"
                                        />
                                    </div>

                                    <div class="flex gap-2">
                                        <x-ui.button
                                            size="sm"
                                            wire:click="mute"
                                            wire:target="mute"
                                            wire:loading.attr="disabled"
                                        >
                                            <span wire:loading.remove wire:target="mute">{{ __('Mute') }}</span>
                                            <span wire:loading wire:target="mute">{{ __('Working…') }}</span>
                                        </x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="stopMuting">{{ __('Cancel') }}</x-ui.button>
                                    </div>
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @endif

                    @if ($resolvingId === (string) $alert->id)
                        <tr>
                            <x-ui.table.td colspan="6" class="bg-fg-surface-sunk">
                                <div class="grid gap-3">
                                    <x-ui.text size="xs">{{ __('Saying how it was dealt with is the record. A recurrence is a new alert, not this row reopened.') }}</x-ui.text>

                                    <x-ui.input
                                        name="resolveReason"
                                        wire:model="resolveReason"
                                        :label="__('How was it resolved?')"
                                        :hint="__('e.g. renewed licence filed as document 01J…; the barrier hatch was closed after the deploy.')"
                                    />

                                    <div class="flex gap-2">
                                        <x-ui.button
                                            size="sm"
                                            wire:click="resolve"
                                            wire:target="resolve"
                                            wire:loading.attr="disabled"
                                        >
                                            <span wire:loading.remove wire:target="resolve">{{ __('Resolve') }}</span>
                                            <span wire:loading wire:target="resolve">{{ __('Working…') }}</span>
                                        </x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="stopResolving">{{ __('Cancel') }}</x-ui.button>
                                    </div>
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <x-ui.table.td colspan="6">
                            <x-ui.empty-state
                                :title="$filter === 'resolved' ? __('Nothing resolved yet') : __('Nothing needs attention')"
                                :description="$filter === 'resolved'
                                    ? __('The register keeps every alert that was closed, and what was written about it.')
                                    : __('No source has reported a condition for :company today. Reminders run nightly and every scheduled self-check raises one when it fails.', ['company' => CurrentCompany::model()->name])"
                            />
                        </x-ui.table.td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->alerts->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->alerts->links() }}
                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total alerts.', [
                        'first' => $this->alerts->firstItem(),
                        'last' => $this->alerts->lastItem(),
                        'total' => $this->alerts->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>
</div>
