<?php

declare(strict_types=1);

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\CreateBorderPost;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Data\BorderPostDto;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\BorderPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The cross-border register — every gate the fleet uses, and every crossing that has
 * been planned against one.
 *
 * The screen is a register, not a workflow: the acts that move a crossing along (the enter,
 * exit, capture and finish) happen on the trip, where the truck, the driver and the money are on one
 * page. What this register answers is the question a dispatcher asks before planning a corridor —
 * "which gates do we actually use, and what is still open at them" — and the accountant's "what did
 * the crossings cost us" (Phase 6 reconciles the rest).
 *
 * Gates are company master data, so registering one lives here behind `crossborder.manage`, one
 * dialog away from the list it feeds. A gate registered twice splits its crossings across two rows
 * that no report rejoins, which is why the Action refuses a duplicate by name.
 */
new #[Title('Cross-border')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    public string $postName = '';

    public string $postCountry = 'BW';

    public string $postLat = '';

    public string $postLng = '';

    public string $postOpenFrom = '';

    public string $postOpenTo = '';

    public string $postNotes = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->crossings);
        }
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->crossings);
    }

    public function canManage(): bool
    {
        return Gate::allows('crossborder.manage');
    }

    public function createPost(CreateBorderPost $create): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'postName' => ['required', 'string', 'max:120'],
            'postCountry' => ['required', 'string', 'size:2', Rule::in(array_keys($this->countryChoices()))],
            'postLat' => ['nullable', 'numeric', 'between:-90,90'],
            'postLng' => ['nullable', 'numeric', 'between:-180,180'],
            'postOpenFrom' => ['nullable', 'date_format:H:i'],
            'postOpenTo' => ['nullable', 'date_format:H:i'],
            'postNotes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $post = $create(new BorderPostDto(
                name: $this->postName,
                countryCode: strtoupper($this->postCountry),
                lat: $this->postLat === '' ? null : $this->postLat,
                lng: $this->postLng === '' ? null : $this->postLng,
                openFrom: $this->postOpenFrom === '' ? null : $this->postOpenFrom,
                openTo: $this->postOpenTo === '' ? null : $this->postOpenTo,
                notes: $this->postNotes === '' ? null : $this->postNotes,
            ));
        } catch (Throwable $refused) {
            $this->addError('post', $refused->getMessage());

            return;
        }

        $this->reset(['postName', 'postLat', 'postLng', 'postOpenFrom', 'postOpenTo', 'postNotes']);
        unset($this->posts);
        $this->dispatch('close-border-post');
        $this->dispatch('toast', message: __(':post registered.', ['post' => $post->name]), kind: 'success');
    }

    /* ------------------------------------------------------------------ reads */

    /** @return LengthAwarePaginator<int, BorderCrossing> */
    #[Computed]
    public function crossings(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $status = $this->statusFilter;

        return BorderCrossing::query()
            ->with(['trip:id,trip_no,status', 'post:id,name,country_code'])
            ->withCount('costs')
            ->withSum('costs as charges_base', 'base_amount')
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(static function ($inner) use ($search): void {
                    $inner->whereHas('trip', static fn ($t) => $t->where('trip_no', 'like', "%{$search}%"))
                        ->orWhereHas('post', static fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** @return list<BorderPost> */
    #[Computed]
    public function posts(): array
    {
        return BorderPost::query()
            ->withCount('crossings')
            ->orderBy('country_code')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    #[Computed]
    public function statusChoices(): array
    {
        return array_map(
            static fn (BorderCrossingStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            BorderCrossingStatus::cases(),
        );
    }

    /** @return array<string, string> */
    public function countryChoices(): array
    {
        /** @var array<string, string> $countries */
        $countries = (array) config('fleet.commercial.countries', []);

        return $countries;
    }

    public function money(int $minorUnits): string
    {
        return Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)
            ->format(CurrentCompany::model()->base_currency_code);
    }

    public function at(?object $moment): string
    {
        return $moment === null
            ? '—'
            : Illuminate\Support\Carbon::instance($moment)->timezone(CurrentCompany::timezone())->format('d M Y H:i');
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Cross-border') }}</x-ui.heading>
            <x-ui.text>
                {{ __('The gates we use and the crossings on our trips. Entering, exiting and charging a gate happen on the trip itself.') }}
            </x-ui.text>
        </div>

        @if ($this->canManage())
            <x-ui.button x-on:click="$dispatch('open-border-post')">{{ __('Register a gate') }}</x-ui.button>
        @endif
    </header>

    <div class="flex flex-wrap items-end gap-3">
        <x-ui.input name="search" wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Trip number or gate…') }}" />

        <div class="grid gap-1">
            <label for="crossing-status" class="text-sm text-fg-ink">{{ __('Status') }}</label>
            <x-ui.select name="statusFilter"
                id="crossing-status"
                wire:model.live="statusFilter"
            >
                <option value="">{{ __('Any status') }}</option>
                @foreach ($this->statusChoices as $choice)
                    <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($this->hasFilters())
            <x-ui.button type="button" size="sm" variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</x-ui.button>
        @endif
    </div>

    <x-ui.table :caption="__('Border crossings, newest first — the trip, the gate and what the gate is still waiting on')">
        <x-slot name="head">
            <x-ui.table.th>{{ __('Trip') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Gate') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Entered') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Exited') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
            <x-ui.table.th numeric>{{ __('Charges') }}</x-ui.table.th>
        </x-slot>

        @forelse ($this->crossings as $crossing)
            <tr wire:key="crossing-{{ $crossing->id }}">
                <x-ui.table.td nowrap>
                    @if ($crossing->trip !== null)
                        <a href="{{ route('operations.trip', $crossing->trip) }}" class="fg-identifier text-sm text-accent-ink underline-offset-4 transition duration-(--t-fast) ease-(--ease-standard) hover:text-accent-hover hover:underline">{{ $crossing->trip->trip_no }}</a>
                    @else
                        <span class="fg-identifier text-sm text-fg-ink-muted">{{ $crossing->trip_id }}</span>
                    @endif
                </x-ui.table.td>

                <x-ui.table.td>
                    <span class="block text-fg-ink">{{ $crossing->post?->name ?? __('Gate #:id', ['id' => $crossing->border_post_id]) }}</span>
                    <span class="block text-xs text-fg-ink-muted">{{ $crossing->country_code }} · {{ $crossing->direction->label() }}</span>
                </x-ui.table.td>

                <x-ui.table.td nowrap>{{ $this->at($crossing->entered_at) }}</x-ui.table.td>
                <x-ui.table.td nowrap>{{ $this->at($crossing->exited_at) }}</x-ui.table.td>

                <x-ui.table.td>
                    <x-ui.badge :tone="$crossing->status->tone()">{{ $crossing->status->label() }}</x-ui.badge>
                </x-ui.table.td>

                <x-ui.table.td numeric nowrap>
                    @if ($crossing->costs_count === 0)
                        <span class="text-fg-ink-muted">{{ __('none') }}</span>
                    @else
                        <span class="text-fg-ink">{{ $this->money((int) $crossing->charges_base) }}</span>
                        <span class="block text-xs text-fg-ink-muted">{{ __(':count charge(s)', ['count' => $crossing->costs_count]) }}</span>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty-state
                        icon="globe-alt"
                        title="{{ $this->hasFilters() ? __('No crossings match') : __('No crossings yet') }}"
                        description="{{ $this->hasFilters() ? __('Nothing on the register answers that search or status.') : __('Plan a gate on a trip and it appears here; the money is captured at the crossing.') }}"
                    />
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <div>
        {{ $this->crossings->links() }}
    </div>

    {{-- The gate register: master data, so it is a flat list rather than a screen of its own. --}}
    <section class="grid gap-4 fg-panel p-5">
        <x-ui.subheading>{{ __('Gates on file') }}</x-ui.subheading>

        @if ($this->posts === [])
            <x-ui.text>{{ __('No gate has been registered. A crossing needs one — register the posts your corridors actually use, once each.') }}</x-ui.text>
        @else
            <x-ui.table :caption="__('The gate posts on file — where a truck may cross, and what the barrier costs')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Gate') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Country') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Open') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Crossings') }}</x-ui.table.th>
                </x-slot>

                @foreach ($this->posts as $post)
                    <tr wire:key="post-{{ $post->id }}">
                        <x-ui.table.td>{{ $post->name }}</x-ui.table.td>
                        <x-ui.table.td>{{ $post->country_code }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $post->openingHours() }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $post->crossings_count }}</x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </section>

    {{-- One dialog per act, because the Action refuses by name and the message belongs beside the form. --}}
    @if ($this->canManage())
        <x-ui.dialog name="border-post" :title="__('Register a border gate')">
            <form wire:submit="createPost" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="postName" wire:model="postName" :label="__('Name')" placeholder="{{ __('Kazungula') }}" />

                    <div class="grid gap-1">
                        <label for="post-country" class="text-sm text-fg-ink">{{ __('Country') }}</label>
                        <x-ui.select name="postCountry" id="post-country" wire:model="postCountry">
                            @foreach ($this->countryChoices() as $code => $name)
                                <option value="{{ $code }}">{{ $code }} · {{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="postLat" wire:model="postLat" :label="__('Latitude (optional)')" inputmode="decimal" />
                    <x-ui.input name="postLng" wire:model="postLng" :label="__('Longitude (optional)')" inputmode="decimal" />
                    <x-ui.input name="postOpenFrom" wire:model="postOpenFrom" :label="__('Opens (optional)')" type="time" />
                    <x-ui.input name="postOpenTo" wire:model="postOpenTo" :label="__('Closes (optional)')" type="time" />
                </div>

                <x-ui.input name="postNotes" wire:model="postNotes" :label="__('Notes')" />

                <p class="text-xs text-fg-ink-muted">
                    {{ __('Coordinates put the gate on the live map; hours are what the gatekeeper told you. Both are optional and can be filled in later.') }}
                </p>

                @error('post')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Register the gate') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-border-post')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
