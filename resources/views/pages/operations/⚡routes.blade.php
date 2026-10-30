<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateRouteRate;
use App\Domain\Operations\Actions\UpdateRoute;
use App\Domain\Operations\Actions\UpdateRouteRate;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\RouteRateDto;
use App\Domain\Operations\Exceptions\RouteRateOverlap;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\RouteRate;
use App\Domain\Operations\RouteRateType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The route register and its rate cards (§3.2, §4.1).
 *
 * One screen for both, because a corridor with no price on it is a plan rather than an offering:
 * the register says where the fleet drives, the panel under it says what the fleet sells that lane
 * for, and a route row with no card says so out loud instead of looking finished.
 *
 * Reading is `routes.view`; writing a corridor or a price is `routes.manage` (§9.3 — Owner and
 * Operations Manager). The **amount** on a card is additionally behind the `seeCustomerTerms` field
 * gate (§9.4: "customer credit terms and agreed rates"), so a Dispatcher can see that GBE-WDH is
 * priced per tonne without learning what the company charges — the same seat that plans the trip.
 *
 * A rate is never overwritten to change history: a new price opens a new window and the old one is
 * closed with an end date. The in-place edit that exists here is for correcting a mis-keyed card
 * before Phase 2 can bill against it; the Action grows the stricter rule when trips arrive.
 */
new #[Title('Routes')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    /** The corridor whose rate cards are open, kept in the URL so a link can point at a price. */
    #[Url('route', except: null)]
    public ?int $selectedRouteId = null;

    public ?int $routeEditingId = null;

    public string $code = '';

    public string $origin = '';

    public string $destination = '';

    public string $originCountryCode = '';

    public string $destinationCountryCode = '';

    public string $distanceKm = '';

    public string $notes = '';

    public bool $routeActive = true;

    public ?int $rateEditingId = null;

    public string $rateCustomerId = '';

    public string $rateType = RouteRateType::PerTonne->value;

    public string $rateAmount = '';

    public string $rateCurrency = '';

    public string $rateValidFrom = '';

    public string $rateValidTo = '';

    public function mount(): void
    {
        $this->rateCurrency = $this->companyCurrency();
        $this->rateValidFrom = $this->today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->routes);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->routes);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function selectRoute(int $id): void
    {
        $route = Route::query()->findOrFail($id);

        abort_unless(Gate::allows('view', $route), 403);

        $this->selectedRouteId = $route->id;
        $this->clearRateForm();

        unset($this->selectedRoute, $this->rates);
    }

    public function closeRates(): void
    {
        $this->selectedRouteId = null;
        $this->clearRateForm();

        unset($this->selectedRoute, $this->rates);
    }

    public function editRoute(int $id): void
    {
        $route = Route::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $route), 403);

        $this->clearRouteForm();

        $this->routeEditingId = $route->id;
        $this->code = $route->code;
        $this->origin = $route->origin;
        $this->destination = $route->destination;
        $this->originCountryCode = $route->origin_country_code;
        $this->destinationCountryCode = $route->destination_country_code;
        $this->distanceKm = (string) $route->distance_km;
        $this->notes = (string) $route->notes;
        $this->routeActive = $route->active;
    }

    public function clearRouteForm(): void
    {
        $this->routeEditingId = null;

        $this->reset([
            'code', 'origin', 'destination', 'originCountryCode', 'destinationCountryCode',
            'distanceKm', 'notes', 'routeActive',
        ]);

        $this->resetValidation();
    }

    public function saveRoute(CreateRoute $create, UpdateRoute $update): void
    {
        $companyId = CurrentCompany::id();

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:24', Rule::unique('routes', 'code')->where('company_id', $companyId)->ignore($this->routeEditingId)],
            'origin' => ['required', 'string', 'max:80'],
            'destination' => ['required', 'string', 'max:80'],
            'originCountryCode' => ['required', 'string', 'size:2', Rule::in(array_keys($this->countries()))],
            'destinationCountryCode' => ['required', 'string', 'size:2', Rule::in(array_keys($this->countries()))],
            'distanceKm' => ['required', 'numeric', 'gt:0', 'lt:1000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = $this->routeEditingId === null ? null : Route::query()->findOrFail($this->routeEditingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Route::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        try {
            $dto = RouteDto::fromArray([
                'code' => $validated['code'],
                'origin' => $validated['origin'],
                'destination' => $validated['destination'],
                'origin_country_code' => $validated['originCountryCode'],
                'destination_country_code' => $validated['destinationCountryCode'],
                'distance_km' => $validated['distanceKm'],
                'notes' => $validated['notes'] ?? null,
                'active' => $this->routeActive,
            ]);

            $route = $existing === null ? $create($dto) : $update($existing, $dto);
        } catch (InvalidArgumentException $refused) {
            $this->addError('routeForm', $refused->getMessage());

            return;
        }

        $this->clearRouteForm();

        // A freshly added corridor opens its (empty) rate card straight away: the clerk has just
        // named a lane and the next question is what it sells for.
        $this->selectedRouteId = $route->id;

        unset($this->routes, $this->selectedRoute, $this->rates);

        $this->dispatch('toast', message: __('Route saved.'), kind: 'success');
    }

    public function editRate(int $id): void
    {
        $rate = RouteRate::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $rate), 403);

        // §9.4 hides the amount, and a form that loaded it would publish exactly the number the
        // gate withholds — the Livewire snapshot is client-readable, like any other property.
        abort_unless($this->canSeeTerms(), 403);

        $this->clearRateForm();

        $this->rateEditingId = $rate->id;
        $this->rateCustomerId = (string) $rate->customer_id;
        $this->rateType = $rate->rate_type->value;
        $this->rateAmount = $rate->amount()->toBigDecimal()->toString();
        $this->rateCurrency = $rate->currency_code;
        $this->rateValidFrom = $rate->valid_from->toDateString();
        $this->rateValidTo = $rate->valid_to?->toDateString() ?? '';
    }

    public function clearRateForm(): void
    {
        $this->rateEditingId = null;

        $this->reset(['rateCustomerId', 'rateType', 'rateAmount', 'rateValidTo']);

        $this->rateType = RouteRateType::PerTonne->value;
        $this->rateCurrency = $this->companyCurrency();
        $this->rateValidFrom = $this->today()->toDateString();

        $this->resetValidation();
    }

    public function saveRate(CreateRouteRate $create, UpdateRouteRate $update): void
    {
        if ($this->selectedRouteId === null) {
            return;
        }

        // A price is filed by someone who may read it; anything else would let a seat with only
        // `routes.manage` type a number it is not allowed to see back.
        abort_unless($this->canSeeTerms(), 403);

        $companyId = CurrentCompany::id();

        $validated = $this->validate([
            'rateCustomerId' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'rateType' => ['required', 'string', Rule::in(RouteRateType::values())],
            'rateAmount' => ['required', 'string'],
            'rateCurrency' => ['required', 'string', 'size:3', Rule::in(array_keys($this->currencies()))],
            'rateValidFrom' => ['required', 'date'],
            'rateValidTo' => ['nullable', 'date', 'after_or_equal:rateValidFrom'],
        ]);

        $existing = $this->rateEditingId === null ? null : RouteRate::query()->findOrFail($this->rateEditingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', RouteRate::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        try {
            $dto = RouteRateDto::fromArray([
                'route_id' => $this->selectedRouteId,
                'customer_id' => (int) $validated['rateCustomerId'],
                'rate_type' => $validated['rateType'],
                'rate_minor_units' => Money::ofMajor($validated['rateAmount'], $validated['rateCurrency'])->minorUnits,
                'currency_code' => $validated['rateCurrency'],
                'valid_from' => $validated['rateValidFrom'],
                'valid_to' => $validated['rateValidTo'] ?? null,
            ]);

            if ($existing === null) {
                $create($dto);
            } else {
                $update($existing, $dto);
            }
        } catch (RouteRateOverlap $overlap) {
            $this->addError('rateForm', $overlap->getMessage());

            return;
        } catch (DomainException|InvalidArgumentException $refused) {
            $this->addError('rateForm', $refused->getMessage());

            return;
        }

        $this->clearRateForm();
        unset($this->rates, $this->selectedRoute, $this->routes);

        $this->dispatch('toast', message: __('Rate saved.'), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, Route> */
    #[Computed]
    public function routes(): LengthAwarePaginator
    {
        return Route::query()
            ->withCount('rates')
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('code', 'like', $like)
                    ->orWhere('origin', 'like', $like)
                    ->orWhere('destination', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('code')
            ->paginate(25);
    }

    #[Computed]
    public function selectedRoute(): ?Route
    {
        if ($this->selectedRouteId === null) {
            return null;
        }

        return Route::query()->withCount('rates')->find($this->selectedRouteId);
    }

    /** @return Collection<int, RouteRate> */
    #[Computed]
    public function rates(): Collection
    {
        return RouteRate::query()
            ->where('route_id', $this->selectedRouteId)
            ->with('customer')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, Customer> */
    #[Computed]
    public function customersForRates(): Collection
    {
        return Customer::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return array<string, Currency> */
    public function currencies(): array
    {
        return Currency::active();
    }

    /** @return array<string, string> */
    public function countries(): array
    {
        return (array) config('fleet.commercial.countries', []);
    }

    /** @return list<RouteRateType> */
    public function rateTypes(): array
    {
        return RouteRateType::cases();
    }

    public function companyCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function today(): Carbon
    {
        return Carbon::today(CurrentCompany::timezone());
    }

    /** "1 074,5 km" — a measured distance, not an amount; Money's formatter does not apply. */
    public function km(float $distance): string
    {
        return rtrim(rtrim(number_format($distance, 2, ',', ' '), '0'), ',');
    }

    public function canSeeTerms(): bool
    {
        return Gate::allows(FieldGate::SeeCustomerTerms->value);
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Routes') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The corridors the fleet runs and what each one sells for. A route names its endpoints and distance; a rate card records what one customer agreed to pay on one basis, over a window of dates.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Route register')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Code, origin or destination')" />

            <div class="grid gap-1.5">
                <label for="routes-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <select id="routes-status-filter" wire:model.live="statusFilter" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                    <option value="">{{ __('Every corridor') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Retired') }}</option>
                </select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Routes')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Route') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Corridor') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Distance') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Prices') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->routes as $route)
                    <tr
                        wire:key="route-{{ $route->id }}"
                        @class(['bg-fg-surface-sunk' => $selectedRouteId === $route->id])
                    >
                        <x-ui.table.td>
                            <span class="font-mono font-medium text-fg-ink">{{ $route->code }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <span class="text-fg-ink">{{ $route->corridor() }}</span>

                            <div class="text-xs text-fg-ink-muted">
                                {{ $route->origin_country_code }} → {{ $route->destination_country_code }}

                                @if ($route->isCrossBorder())
                                    · {{ __('cross-border') }}
                                @endif
                            </div>
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ $this->km($route->distance_km) }} km</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($route->rates_count === 0)
                                <x-ui.badge tone="warning">{{ __('no price yet') }}</x-ui.badge>
                            @else
                                <span class="text-sm text-fg-ink-secondary">{{ trans_choice(':count card|:count cards', $route->rates_count) }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($route->active)
                                <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('retired') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td class="text-right">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="selectRoute({{ $route->id }})"
                                    :aria-pressed="$selectedRouteId === $route->id ? 'true' : 'false'"
                                >{{ __('Rate cards') }}</x-ui.button>

                                @can('update', $route)
                                    <x-ui.button variant="ghost" size="sm" wire:click="editRoute({{ $route->id }})">{{ __('Edit') }}</x-ui.button>
                                @endcan
                            </div>
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state
                                icon="map"
                                :title="$this->hasFilters() ? __('No routes match these filters') : __('No routes yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search or status filter to see the rest of the register.')
                                    : __('Add a corridor the fleet runs; a job and its price both name one.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->routes->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->routes->links() }}

                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total routes.', [
                        'first' => $this->routes->firstItem(),
                        'last' => $this->routes->lastItem(),
                        'total' => $this->routes->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>

    @if ($this->selectedRoute !== null)
        <x-ui.card :title="__('Rate cards — :route', ['route' => $this->selectedRoute->label()])">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <x-ui.text size="sm" color="muted">
                    {{ __('Agreed prices for this corridor. A new price closes the old window and opens a new one, so a trip can always be traced to the card that priced it.') }}
                </x-ui.text>

                <x-ui.button variant="ghost" size="sm" wire:click="closeRates">{{ __('Close') }}</x-ui.button>
            </div>

            <x-ui.table :caption="__('Rate cards')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Customer') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Basis') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Rate') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Window') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rates as $rate)
                        <tr wire:key="rate-{{ $rate->id }}">
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $rate->customer?->name ?? __('Customer #:id', ['id' => $rate->customer_id]) }}</span>

                                @if ($rate->customer?->code)
                                    <div class="font-mono text-xs text-fg-ink-muted">{{ $rate->customer->code }}</div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>{{ $rate->rate_type->label() }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>
                                <x-ui.gated gate="seeCustomerTerms" :label="__('the agreed rate')">
                                    {{ $rate->amount()->format($this->companyCurrency()) }}
                                    <span class="text-xs text-fg-ink-muted">{{ $rate->rate_type->unit() }}</span>
                                </x-ui.gated>
                            </x-ui.table.td>
                            <x-ui.table.td nowrap>
                                <span class="text-fg-ink">{{ $rate->valid_from->format('d M Y') }}</span>
                                <span class="text-fg-ink-muted">{{ __('to') }}</span>
                                <span class="text-fg-ink">{{ $rate->valid_to?->format('d M Y') ?? __('open') }}</span>
                            </x-ui.table.td>
                            <x-ui.table.td>
                                @php($state = $rate->stateOn($this->today()))
                                <x-ui.badge :tone="$state->tone()">{{ __($state->label()) }}</x-ui.badge>
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                @can('update', $rate)
                                    @if ($this->canSeeTerms())
                                        <x-ui.button variant="ghost" size="sm" wire:click="editRate({{ $rate->id }})">{{ __('Edit') }}</x-ui.button>
                                    @endif
                                @endcan
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state
                                    icon="banknotes"
                                    :title="__('No price on this corridor yet')"
                                    :description="__('A route with no card cannot be quoted. Add the first customer price below.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @can('routes.manage')
                @if ($this->canSeeTerms())
                <form class="mt-6 grid gap-4 border-t border-fg-line pt-6" wire:submit="saveRate">
                    <x-ui.heading size="xs" level="3">{{ $rateEditingId === null ? __('Add a rate card') : __('Edit rate card') }}</x-ui.heading>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="grid gap-1.5">
                            <label for="rate-customer" class="text-xs font-medium text-fg-ink-secondary">{{ __('Customer') }}</label>

                            <select id="rate-customer" wire:model="rateCustomerId" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                <option value="">{{ __('Choose a customer') }}</option>

                                @foreach ($this->customersForRates as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->label() }}</option>
                                @endforeach
                            </select>

                            @error('rateCustomerId')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-1.5">
                            <label for="rate-basis" class="text-xs font-medium text-fg-ink-secondary">{{ __('Basis') }}</label>

                            <select id="rate-basis" wire:model="rateType" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach ($this->rateTypes() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>

                            @error('rateType')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-1.5">
                            <label for="rate-currency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Currency') }}</label>

                            <select id="rate-currency" wire:model="rateCurrency" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach ($this->currencies() as $currency)
                                    <option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>
                                @endforeach
                            </select>

                            @error('rateCurrency')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-ui.input
                            wire:model="rateAmount"
                            name="rateAmount"
                            numeric
                            :label="__('Amount')"
                            :hint="__('In the currency chosen; the agreed figure, not converted to pula.')"
                        />

                        <x-ui.input wire:model="rateValidFrom" name="rateValidFrom" type="date" :label="__('Valid from')" required />
                        <x-ui.input wire:model="rateValidTo" name="rateValidTo" type="date" :label="__('Valid to (optional)')" :hint="__('Leave open until a replacement price closes it.')" />
                    </div>

                    @error('rateForm')
                        <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="flex items-center gap-3">
                        {{-- Outline: the rate card is a price attached to a corridor, so the filled action of
                             this page stays with the route form below it (§5, one per view). --}}
                        <x-ui.button variant="outline" type="submit">{{ $rateEditingId === null ? __('Save rate') : __('Update rate') }}</x-ui.button>

                        @if ($rateEditingId !== null)
                            <x-ui.button variant="ghost" type="button" wire:click="clearRateForm">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
                @else
                    <x-ui.alert class="mt-6" :title="__('Prices are hidden for your role')">
                        {{ __('You can read this corridor and its cards, but filing a price also needs the customer-terms capability — the amount is the customer’s commercial detail. Ask the Owner or Operations Manager to add or correct a card.') }}
                    </x-ui.alert>
                @endif
            @endcan
        </x-ui.card>
    @endif

    @can('routes.manage')
        <x-ui.card :title="$routeEditingId === null ? __('Add a corridor') : __('Edit corridor')">
            <form class="grid gap-4" wire:submit="saveRoute">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.input wire:model="code" name="code" :label="__('Code')" :hint="__('What dispatch says out loud, e.g. GBE-WDH.')" required />
                    <x-ui.input wire:model="origin" name="origin" :label="__('Origin')" required />
                    <x-ui.input wire:model="destination" name="destination" :label="__('Destination')" required />

                    <div class="grid gap-1.5">
                        <label for="route-origin-country" class="text-xs font-medium text-fg-ink-secondary">{{ __('Origin country') }}</label>

                        <select id="route-origin-country" wire:model="originCountryCode" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                            <option value="">{{ __('Choose a country') }}</option>

                            @foreach ($this->countries() as $iso => $country)
                                <option value="{{ $iso }}">{{ $country }}</option>
                            @endforeach
                        </select>

                        @error('originCountryCode')
                            <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-1.5">
                        <label for="route-destination-country" class="text-xs font-medium text-fg-ink-secondary">{{ __('Destination country') }}</label>

                        <select id="route-destination-country" wire:model="destinationCountryCode" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                            <option value="">{{ __('Choose a country') }}</option>

                            @foreach ($this->countries() as $iso => $country)
                                <option value="{{ $iso }}">{{ $country }}</option>
                            @endforeach
                        </select>

                        @error('destinationCountryCode')
                            <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-ui.input wire:model="distanceKm" name="distanceKm" numeric :label="__('Distance in km')" :hint="__('A measured distance, to one decimal place if needed.')" required />
                </div>

                <div class="grid gap-1.5">
                    <label for="route-notes" class="text-xs font-medium text-fg-ink-secondary">{{ __('Notes (optional)') }}</label>

                    <textarea
                        id="route-notes"
                        wire:model="notes"
                        rows="2"
                        class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink placeholder:text-fg-ink-muted"
                    ></textarea>

                    @error('notes')
                        <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.checkbox name="routeActive" wire:model="routeActive" :label="__('Active — still run by the fleet')" />

                @error('routeForm')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ $routeEditingId === null ? __('Save route') : __('Update route') }}</x-ui.button>

                    @if ($routeEditingId !== null)
                        <x-ui.button variant="ghost" type="button" wire:click="clearRouteForm">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan
</div>
