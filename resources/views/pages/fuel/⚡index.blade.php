<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Actions\CreateFuelStation;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The fuel register (§3.2, §4.3): the slips the fleet bought, the stations that sold them, the
 * tanks the trucks carry, and the issues those tanks have dispensed.
 *
 * An issue is not recorded here. It belongs to a trip — the trip names the truck, the truck names
 * the tank, and the cost accounts 5100 requires the trip dimension (§6.1) — so issuing happens on
 * the trip's own fuel panel. What this screen answers is the office's question before a trip
 * leaves: which slips still have litres on them, and which trucks are actually fitted for fuel.
 *
 * Writing is `fuel.record` (§9.3 — Owner, Operations Manager, Workshop Manager, Stores Clerk);
 * the Accountant reads with `fuel.view`. Every write is a dialog of its own, because each Action
 * refuses by name and the sentence belongs beside the form that caused it.
 */
new #[Title('Fuel')] class extends Component {
    use WithPagination;

    public string $stationName = '';

    public string $stationTown = '';

    public string $stationCountry = 'BW';

    public string $tankTruckId = '';

    public string $tankKind = 'main';

    public ?string $tankCapacity = null;

    public ?string $slipStationId = null;

    public string $slipDate = '';

    public string $slipInvoice = '';

    public string $slipLitres = '';

    public string $slipPrice = '';

    public string $slipCurrency = '';

    public string $slipRate = '1';

    public string $slipRateSource = FxRateSource::Manual->value;

    public function mount(): void
    {
        $this->slipDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->slipCurrency = $this->baseCurrency();
    }

    public function canRecord(): bool
    {
        return Gate::allows('fuel.record');
    }

    public function addStation(CreateFuelStation $create): void
    {
        abort_unless($this->canRecord(), 403);

        $validated = $this->validate([
            'stationName' => ['required', 'string', 'max:120'],
            'stationTown' => ['nullable', 'string', 'max:80'],
            'stationCountry' => ['required', 'string', 'size:2'],
        ]);

        try {
            $station = $create($validated['stationName'], $validated['stationTown'] ?? null, $validated['stationCountry']);
        } catch (InvalidArgumentException $refused) {
            $this->addError('station', $refused->getMessage());

            return;
        }

        $this->reset(['stationName', 'stationTown']);
        unset($this->stations);
        $this->dispatch('close-fuel-station');
        $this->dispatch('toast', message: __(':station registered.', ['station' => $station->name]), kind: 'success');
    }

    public function fitTank(CreateFuelTank $fit): void
    {
        abort_unless($this->canRecord(), 403);

        $this->tankCapacity = trim((string) $this->tankCapacity) === '' ? null : $this->tankCapacity;

        $validated = $this->validate([
            'tankTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'tankKind' => ['required', Rule::in(array_map(static fn (FuelTankKind $kind): string => $kind->value, FuelTankKind::cases()))],
            'tankCapacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            $tank = $fit(
                (int) $validated['tankTruckId'],
                FuelTankKind::from($validated['tankKind']),
                ($validated['tankCapacity'] ?? null) === null ? null : (int) $validated['tankCapacity'],
            );
        } catch (InvalidArgumentException $refused) {
            $this->addError('tank', $refused->getMessage());

            return;
        }

        $this->reset(['tankTruckId', 'tankCapacity']);
        unset($this->tanks);
        $this->dispatch('close-fuel-tank');
        $this->dispatch('toast', message: __(':tank fitted.', ['tank' => $tank->label()]), kind: 'success');
    }

    public function recordSlip(CreateFuelPurchase $create): void
    {
        abort_unless($this->canRecord(), 403);

        $this->slipStationId = trim((string) $this->slipStationId) === '' ? null : $this->slipStationId;

        $validated = $this->validate([
            'slipDate' => ['required', 'date'],
            'slipInvoice' => ['nullable', 'string', 'max:60'],
            'slipStationId' => ['nullable', 'integer', Rule::exists('fuel_stations', 'id')->where('company_id', CurrentCompany::id())],
            'slipLitres' => ['required', 'string'],
            'slipPrice' => ['required', 'string'],
            'slipCurrency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'slipRate' => ['nullable', 'numeric', 'gt:0'],
            'slipRateSource' => ['required', Rule::in(array_map(static fn (FxRateSource $source): string => $source->value, FxRateSource::cases()))],
        ]);

        $currency = Currency::normalise($validated['slipCurrency']);
        $base = $this->baseCurrency();
        $purchasedOn = Carbon::parse($validated['slipDate']);

        try {
            $litresX100 = $this->scaledHundredths($validated['slipLitres'], __('Litres'));
            $priceMinor = $this->scaledHundredths($validated['slipPrice'], __('Price per litre'));

            // The slip's own arithmetic: the amount is litres × price, rounded once. The DTO refuses
            // a paid amount that disagrees, so computing it here is what keeps the two in step.
            $amountMinor = intdiv($litresX100 * $priceMinor + 50, 100);

            $slip = $create(new FuelPurchaseDto(
                amount: Money::ofMinor($amountMinor, $currency),
                rate: $currency === $base ? '1' : (string) ($validated['slipRate'] ?? '1'),
                rateSource: FxRateSource::from($validated['slipRateSource']),
                rateDate: $purchasedOn,
                purchasedOn: $purchasedOn,
                litresX100: $litresX100,
                pricePerLitreMinor: $priceMinor,
                stationId: ($validated['slipStationId'] ?? null) === null ? null : (int) $validated['slipStationId'],
                invoiceNo: $validated['slipInvoice'] ?? null,
            ), (int) auth()->id());
        } catch (InvalidArgumentException $refused) {
            $this->addError('slip', $refused->getMessage());

            return;
        }

        $this->reset(['slipInvoice', 'slipLitres', 'slipPrice', 'slipRate', 'slipRateSource']);
        $this->slipDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->slipRate = '1';
        $this->slipRateSource = FxRateSource::Manual->value;
        unset($this->purchases);
        $this->dispatch('close-fuel-slip');
        $this->dispatch('toast', message: __('Slip recorded: :litres L.', ['litres' => $slip->litresMajor()]), kind: 'success');
    }

    public function isForeignSlip(): bool
    {
        return Currency::normalise($this->slipCurrency) !== $this->baseCurrency();
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    /** "45.32" → 4532, without ever building a float (§20.4). */
    private function scaledHundredths(string $input, string $label): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__(':field must be a number with at most two decimals.', ['field' => $label]));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /* ------------------------------------------------------------------ reads */

    /** @return LengthAwarePaginator<int, FuelPurchase> */
    #[Computed]
    public function purchases(): LengthAwarePaginator
    {
        return FuelPurchase::query()
            ->with(['station', 'issues'])
            ->orderByDesc('purchased_on')
            ->orderByDesc('created_at')
            ->paginate(15, pageName: 'slips');
    }

    /** @return Collection<int, FuelStation> */
    #[Computed]
    public function stations(): Collection
    {
        return FuelStation::query()->orderBy('name')->get();
    }

    /** @return Collection<int, FuelTank> */
    #[Computed]
    public function tanks(): Collection
    {
        return FuelTank::query()->with('truck')->orderBy('truck_id')->orderBy('kind')->get();
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()->orderBy('fleet_no')->get(['id', 'fleet_no', 'registration_no']);
    }

    /** @return LengthAwarePaginator<int, FuelIssue> */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return FuelIssue::query()
            ->with(['trip:id,trip_no', 'truck:id,fleet_no,registration_no', 'tank', 'purchase:id,invoice_no'])
            ->orderByDesc('created_at')
            ->paginate(15, pageName: 'issues');
    }

    /** @return list<FuelTankKind> */
    public function tankKinds(): array
    {
        return FuelTankKind::cases();
    }

    /** @return array<string, Currency> */
    public function currencies(): array
    {
        return Currency::active();
    }

    /** @return list<array{value: string, label: string}> */
    public function rateSourceChoices(): array
    {
        $labels = [
            FxRateSource::Manual->value => __('A rate I typed'),
            FxRateSource::RbgDaily->value => __('RBG daily rate'),
            FxRateSource::Bpb->value => __('BPB published rate'),
            FxRateSource::BankStatement->value => __('What the bank charged'),
        ];

        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $labels[$value] ?? $value],
            array_keys($labels),
        );
    }

    /** @return array<string, string> */
    public function countryChoices(): array
    {
        /** @var array<string, string> $countries */
        $countries = (array) config('fleet.commercial.countries', []);

        return $countries;
    }

    public function money(Money $money): string
    {
        return $money->format($this->baseCurrency());
    }

    public function litres(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
    }
}; ?>

<div class="grid gap-6">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Fuel') }}</x-ui.heading>
            <x-ui.text>
                {{ __('The slips the fleet bought and what is left on each. Fuel is issued on the trip that burns it — open a trip and use its fuel panel.') }}
            </x-ui.text>
        </div>

        @if ($this->canRecord())
            <x-ui.button x-on:click="$dispatch('open-fuel-slip')">{{ __('Record a slip') }}</x-ui.button>
        @endif
    </header>

    <x-ui.card :title="__('Diesel bought')" class="min-w-0">
        <x-ui.table :caption="__('Fuel purchases, newest first — the slip, its station and the litres still to be issued')">
            <x-slot name="head">
                <x-ui.table.th>{{ __('Slip') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Bought') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Litres') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Price / L') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Left on slip') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Ledger') }}</x-ui.table.th>
            </x-slot>

            @forelse ($this->purchases as $slip)
                @php($remaining = $slip->litresRemainingX100())
                <tr wire:key="slip-{{ $slip->id }}">
                    <x-ui.table.td>
                        <span class="text-fg-ink">{{ $slip->invoice_no ?? __('No invoice number') }}</span>
                        <span class="block text-xs text-fg-ink-muted">{{ $slip->station?->label() ?? __('No station named') }}</span>
                    </x-ui.table.td>
                    <x-ui.table.td nowrap>{{ $slip->purchased_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $slip->litresMajor() }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($slip->pricePerLitre()) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($slip->amountMoney()) }}</x-ui.table.td>
                    <x-ui.table.td numeric>
                        <span class="{{ $remaining === 0 ? 'text-fg-ink-muted' : 'text-fg-ink' }}">{{ $this->litres($remaining) }}</span>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        @if ($slip->posted_to_ledger)
                            <x-ui.badge tone="success">{{ __('posted') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="neutral">{{ __('awaiting the bill') }}</x-ui.badge>
                        @endif
                    </x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state
                            icon="circle-stack"
                            title="{{ __('No fuel has been bought yet') }}"
                            description="{{ __('Record the first slip: the litres and the price the pump charged. Issuing it to a trip is the next step, on that trip.') }}"
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        @if ($this->purchases->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->purchases->links() }}
            </div>
        @endif
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="grid min-w-0 gap-4 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-ui.subheading>{{ __('Stations on file') }}</x-ui.subheading>

                @if ($this->canRecord())
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-fuel-station')">{{ __('Register a station') }}</x-ui.button>
                @endif
            </div>

            @if ($this->stations->isEmpty())
                <x-ui.text>{{ __('No station has been registered. A slip may name none, but naming the pump is what makes its price history readable.') }}</x-ui.text>
            @else
                <x-ui.table :caption="__('The fuel stations on file, with their town and country')">
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Station') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Town') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Country') }}</x-ui.table.th>
                    </x-slot>

                    @foreach ($this->stations as $station)
                        <tr wire:key="station-{{ $station->id }}">
                            <x-ui.table.td>{{ $station->name }}</x-ui.table.td>
                            <x-ui.table.td>{{ $station->town ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td>{{ $station->country_code }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </section>

        <section class="grid min-w-0 gap-4 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-ui.subheading>{{ __('Tanks on trucks') }}</x-ui.subheading>

                @if ($this->canRecord())
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-fuel-tank')">{{ __('Fit a tank') }}</x-ui.button>
                @endif
            </div>

            @if ($this->tanks->isEmpty())
                <x-ui.text>{{ __('No tank has been fitted. An issue has to name one, and a truck states its capacities on its own record.') }}</x-ui.text>
            @else
                <x-ui.table :caption="__('Every tank fitted to a truck, with its capacity in litres')">
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Tank') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Capacity') }}</x-ui.table.th>
                    </x-slot>

                    @foreach ($this->tanks as $tank)
                        <tr wire:key="tank-{{ $tank->id }}">
                            <x-ui.table.td>{{ $tank->truck?->label() ?? __('Truck #:id', ['id' => $tank->truck_id]) }}</x-ui.table.td>
                            <x-ui.table.td>{{ $tank->kind->label() }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $tank->capacity_litres }} L</x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </section>
    </div>

    <x-ui.card :title="__('Fuel issued')" class="min-w-0">
        <x-ui.table :caption="__('Every fuel issue recorded, newest first — the trip, the tank and the cost posted')">
            <x-slot name="head">
                <x-ui.table.th>{{ __('Trip') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Tank') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Litres') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Cost') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
            </x-slot>

            @forelse ($this->issues as $issue)
                <tr wire:key="issue-{{ $issue->id }}">
                    <x-ui.table.td nowrap>
                        @if ($issue->trip !== null)
                            <a href="{{ route('operations.trip', $issue->trip) }}" class="fg-identifier text-sm text-accent">{{ $issue->trip->trip_no }}</a>
                        @else
                            <span class="text-xs text-fg-ink-muted">{{ __('bulk tank') }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>{{ $issue->truck?->label() ?? __('Truck #:id', ['id' => $issue->truck_id]) }}</x-ui.table.td>
                    <x-ui.table.td>{{ $issue->tank?->kind->label() ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $issue->litresMajor() }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $issue->odometer_km }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($issue->cost()) }}</x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-ui.badge>
                    </x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state
                            icon="truck"
                            title="{{ __('No fuel has been issued') }}"
                            description="{{ __('Issues are recorded on a trip, against that trip’s own truck and tank.') }}"
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        @if ($this->issues->isNotEmpty())
            <div class="mt-4">
                {{ $this->issues->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($this->canRecord())
        <x-ui.dialog name="fuel-station" :title="__('Register a fuel station')">
            <form wire:submit="addStation" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input name="stationName" wire:model="stationName" :label="__('Name')" placeholder="{{ __('Engen Gaborone') }}" />
                    <x-ui.input name="stationTown" wire:model="stationTown" :label="__('Town (optional)')" />
                    <x-ui.select name="stationCountry" id="station-country" wire:model="stationCountry" :label="__('Country')">
                        @foreach ($this->countryChoices() as $code => $name)
                            <option value="{{ $code }}">{{ $code }} · {{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @error('station')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Register the station') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-fuel-station')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="fuel-tank" :title="__('Fit a tank to a truck')">
            <form wire:submit="fitTank" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.select name="tankTruckId" id="tank-truck" wire:model="tankTruckId" :label="__('Truck')">
                        <option value="">{{ __('Choose a truck') }}</option>
                        @foreach ($this->trucks as $truck)
                            <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="tankKind" id="tank-kind" wire:model="tankKind" :label="__('Tank')">
                        @foreach ($this->tankKinds() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input name="tankCapacity" wire:model="tankCapacity" numeric :label="__('Capacity in litres (optional)')" :hint="__('Leave empty to take the truck’s stated capacity.')" />
                </div>

                @error('tank')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Fit the tank') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-fuel-tank')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="fuel-slip" :title="__('Record a fuel slip')" max-width="2xl">
            <form wire:submit="recordSlip" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.select name="slipStationId" id="slip-station" wire:model="slipStationId" :label="__('Station (optional)')">
                        <option value="">{{ __('Not named on the slip') }}</option>
                        @foreach ($this->stations as $station)
                            <option value="{{ $station->id }}">{{ $station->label() }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input name="slipDate" wire:model="slipDate" :label="__('Bought on')" type="date" />

                    <x-ui.input name="slipInvoice" wire:model="slipInvoice" :label="__('Invoice or slip number')" />

                    <x-ui.input name="slipLitres" wire:model="slipLitres" numeric :label="__('Litres')" placeholder="{{ __('400.00') }}" />

                    <x-ui.input name="slipPrice" wire:model="slipPrice" numeric :label="__('Price per litre')" :hint="__('As the pump printed it, in the currency chosen below.')" />

                    <x-ui.select name="slipCurrency" id="slip-currency" wire:model.live="slipCurrency" :label="__('Currency')">
                        @foreach ($this->currencies() as $currency)
                            <option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if ($this->isForeignSlip())
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input name="slipRate" wire:model="slipRate" :label="__('Rate to :currency (1 foreign = ?)', ['currency' => $this->baseCurrency()])" type="number" step="0.00000001" />

                        <x-ui.select name="slipRateSource" id="slip-rate-source" wire:model="slipRateSource" :label="__('Where the rate came from')">
                            @foreach ($this->rateSourceChoices() as $choice)
                                <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                @endif

                <p class="text-xs text-fg-ink-muted">
                    {{ __('The amount is litres × price, computed here — the register refuses a slip whose figures disagree. The slip is posted to the ledger with the supplier bill (§6.8, Phase 5).') }}
                </p>

                @error('slip')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Record the slip') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-fuel-slip')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
