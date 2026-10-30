<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\Actions\CreateBulkTank;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Actions\CreateFuelStation;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\Actions\DecideFuelVariance;
use App\Domain\Fuel\Actions\ReconcileBulkTank;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\FuelVarianceState;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Fuel\Models\FuelVariance;
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
 * tanks the trucks carry, the yard tank the deliveries go into, and the issues those tanks have
 * dispensed.
 *
 * An issue is not recorded here. It belongs to a trip — the trip names the truck, the truck names
 * the tank, and the cost accounts 5100 requires the trip dimension (§6.1) — so issuing happens on
 * the trip's own fuel panel. What this screen answers is the office's question before a trip
 * leaves: which slips still have litres on them, which trucks are actually fitted for fuel, and what
 * the yard tank holds after the last delivery and the last dip.
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

    public string $bulkTankName = '';

    public ?string $bulkTankCapacity = null;

    public string $dipTankId = '';

    public string $dipLitres = '';

    public string $dipDate = '';

    public string $dipNote = '';

    public ?string $slipStationId = null;

    public ?string $slipBulkTankId = null;

    public string $slipDate = '';

    public string $slipInvoice = '';

    public string $slipLitres = '';

    public string $slipPrice = '';

    public string $slipCurrency = '';

    public string $slipRate = '1';

    public string $slipRateSource = FxRateSource::Manual->value;

    public string $varianceId = '';

    public string $varianceDecision = FuelVarianceState::Acknowledged->value;

    public string $varianceNote = '';

    public function mount(): void
    {
        $this->slipDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->dipDate = $this->slipDate;
        $this->slipCurrency = $this->baseCurrency();
    }

    public function canRecord(): bool
    {
        return Gate::allows('fuel.record');
    }

    public function canDecideVariance(): bool
    {
        return Gate::allows('fuel.waive_variance');
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

    public function addBulkTank(CreateBulkTank $open): void
    {
        abort_unless($this->canRecord(), 403);

        $this->bulkTankCapacity = trim((string) $this->bulkTankCapacity) === '' ? null : $this->bulkTankCapacity;

        $validated = $this->validate([
            'bulkTankName' => ['required', 'string', 'max:80'],
            'bulkTankCapacity' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            $tank = $open($validated['bulkTankName'], (int) $validated['bulkTankCapacity']);
        } catch (InvalidArgumentException $refused) {
            $this->addError('bulkTank', $refused->getMessage());

            return;
        }

        $this->reset(['bulkTankName', 'bulkTankCapacity']);
        unset($this->bulkTanks);
        $this->dispatch('close-bulk-tank');
        $this->dispatch('toast', message: __(':tank opened, empty.', ['tank' => $tank->name]), kind: 'success');
    }

    public function reconcile(ReconcileBulkTank $reconcile): void
    {
        abort_unless($this->canRecord(), 403);

        $validated = $this->validate([
            'dipTankId' => ['required', 'integer', Rule::exists('bulk_tanks', 'id')->where('company_id', CurrentCompany::id())],
            'dipLitres' => ['required', 'string'],
            'dipDate' => ['required', 'date'],
            'dipNote' => ['nullable', 'string', 'max:160'],
        ]);

        $tank = BulkTank::query()->findOrFail((int) $validated['dipTankId']);

        try {
            $movement = $reconcile(
                $tank,
                $this->scaledHundredths($validated['dipLitres'], __('The dip reading')),
                Carbon::parse($validated['dipDate']),
                $validated['dipNote'] ?? null,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException $refused) {
            $this->addError('dip', $refused->getMessage());

            return;
        }

        $this->reset(['dipTankId', 'dipLitres', 'dipNote']);
        $this->dipDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        unset($this->bulkTanks, $this->purchases);
        $this->dispatch('close-bulk-dip');
        $this->dispatch('toast', message: __('Book adjusted by :litres L.', ['litres' => $movement->litresDeltaMajor()]), kind: 'success');
    }

    public function recordSlip(CreateFuelPurchase $create): void
    {
        abort_unless($this->canRecord(), 403);

        $this->slipStationId = trim((string) $this->slipStationId) === '' ? null : $this->slipStationId;
        $this->slipBulkTankId = trim((string) $this->slipBulkTankId) === '' ? null : $this->slipBulkTankId;

        $validated = $this->validate([
            'slipDate' => ['required', 'date'],
            'slipInvoice' => ['nullable', 'string', 'max:60'],
            'slipStationId' => ['nullable', 'integer', Rule::exists('fuel_stations', 'id')->where('company_id', CurrentCompany::id())],
            'slipBulkTankId' => ['nullable', 'integer', Rule::exists('bulk_tanks', 'id')->where('company_id', CurrentCompany::id())],
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
                bulkTankId: ($validated['slipBulkTankId'] ?? null) === null ? null : (int) $validated['slipBulkTankId'],
            ), (int) auth()->id());
        } catch (InvalidArgumentException $refused) {
            $this->addError('slip', $refused->getMessage());

            return;
        }

        $this->reset(['slipInvoice', 'slipLitres', 'slipPrice', 'slipRate', 'slipRateSource', 'slipBulkTankId']);
        $this->slipDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->slipRate = '1';
        $this->slipRateSource = FxRateSource::Manual->value;
        unset($this->purchases, $this->bulkTanks);
        $this->dispatch('close-fuel-slip');
        $this->dispatch('toast', message: __('Slip recorded: :litres L.', ['litres' => $slip->litresMajor()]), kind: 'success');
    }

    public function isForeignSlip(): bool
    {
        return Currency::normalise($this->slipCurrency) !== $this->baseCurrency();
    }

    /** Open the decision dialog on one variance, with the note field empty. */
    public function startVariance(int $id): void
    {
        abort_unless($this->canDecideVariance(), 403);

        $this->varianceId = (string) $id;
        $this->varianceDecision = FuelVarianceState::Acknowledged->value;
        $this->varianceNote = '';
        $this->resetErrorBag('variance');
        $this->dispatch('open-fuel-variance');
    }

    public function decideVariance(DecideFuelVariance $decide): void
    {
        abort_unless($this->canDecideVariance(), 403);

        $validated = $this->validate([
            'varianceDecision' => ['required', Rule::in([
                FuelVarianceState::Acknowledged->value,
                FuelVarianceState::Disputed->value,
                FuelVarianceState::Resolved->value,
            ])],
            'varianceNote' => ['required', 'string', 'max:160'],
        ]);

        $variance = FuelVariance::query()->findOrFail((int) $this->varianceId);

        try {
            $decided = $decide(
                $variance,
                FuelVarianceState::from($validated['varianceDecision']),
                $validated['varianceNote'],
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException $refused) {
            $this->addError('variance', $refused->getMessage());

            return;
        }

        unset($this->variances);
        $this->reset(['varianceId', 'varianceNote']);
        $this->dispatch('close-fuel-variance');
        $this->dispatch('toast', message: __('Variance :state.', [
            'state' => mb_strtolower($decided->state->label()),
        ]), kind: 'success');
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
            ->with(['station', 'issues', 'bulkTank'])
            ->orderByDesc('purchased_on')
            ->orderByDesc('created_at')
            ->paginate(15, pageName: 'slips');
    }

    /** @return Collection<int, BulkTank> */
    #[Computed]
    public function bulkTanks(): Collection
    {
        return BulkTank::query()->orderBy('name')->get();
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

    /** @return LengthAwarePaginator<int, FuelVariance> */
    #[Computed]
    public function variances(): LengthAwarePaginator
    {
        return FuelVariance::query()
            ->with(['trip:id,trip_no', 'truck:id,fleet_no,registration_no'])
            ->orderByDesc('detected_at')
            ->paginate(10, pageName: 'variances');
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

    /** A bulk tank's book in a sentence the card can print without another query. */
    public function tankSummary(BulkTank $tank): string
    {
        return sprintf(
            '%s L at %s',
            $tank->stockLitres(),
            $tank->averageMoney()->format($this->baseCurrency()),
        );
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
                        @if ($slip->bulkTank !== null)
                            <span class="block text-xs text-fg-ink-muted">{{ __('into :tank', ['tank' => $slip->bulkTank->name]) }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td nowrap>{{ $slip->purchased_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $slip->litresMajor() }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($slip->pricePerLitre()) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($slip->amountMoney()) }}</x-ui.table.td>
                    <x-ui.table.td numeric>
                        @if ($slip->isBulkDelivery())
                            <span class="text-xs text-fg-ink-muted">{{ __('in the tank') }}</span>
                        @else
                            <span class="{{ $remaining === 0 ? 'text-fg-ink-muted' : 'text-fg-ink' }}">{{ $this->litres($remaining) }}</span>
                        @endif
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

    <x-ui.card :title="__('Bulk tank')" class="min-w-0">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-ui.text size="sm" color="muted">
                {{ __('Diesel delivered into the yard tank is stock until a trip draws from it, at the tank’s moving average. A slip is a purchase the trucks draw from; a delivery into a tank is an asset the moment it is unloaded.') }}
            </x-ui.text>

            @if ($this->canRecord())
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-bulk-tank')">{{ __('Open a tank') }}</x-ui.button>

                    @if ($this->bulkTanks->isNotEmpty())
                        <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-bulk-dip')">{{ __('Record a dip') }}</x-ui.button>
                    @endif
                </div>
            @endif
        </div>

        @if ($this->bulkTanks->isEmpty())
            <x-ui.empty-state
                icon="circle-stack"
                title="{{ __('No bulk tank yet') }}"
                description="{{ __('Deliveries go onto slips by default. Open a tank to hold diesel in stock instead — its litres, its value and its moving average move together, and a dip reconciles the book against the gauge.') }}"
            />
        @else
            <x-ui.table :caption="__('Every bulk tank, with the stock it holds, the average it is worth and its reconciliation state')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Tank') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Capacity') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('In stock') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Average / L') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Value') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                </x-slot>

                @foreach ($this->bulkTanks as $tank)
                    <tr wire:key="bulk-tank-{{ $tank->id }}">
                        <x-ui.table.td>{{ $tank->name }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $tank->capacity_litres }} L</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $tank->stockLitres() }} L</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $tank->averageMoney()->format($this->baseCurrency()) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $tank->valueMoney()->format($this->baseCurrency()) }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($tank->isOverCapacity())
                                <x-ui.badge tone="warning">{{ __('over capacity') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="success">{{ __('in balance') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Consumption variance')" class="min-w-0">
        <x-ui.text size="sm" color="muted" class="mb-4">
            {{ __('When a trip’s litres per 100 km differ from its truck’s expected figure by more than the threshold, completing the trip raises a question here. Deciding one records the sentence that settled it; resolving it closes the alert it raised.') }}
        </x-ui.text>

        @if ($this->variances->isEmpty())
            <x-ui.empty-state
                icon="circle-stack"
                title="{{ __('No variance to answer') }}"
                description="{{ __('A variance appears when a completed trip burns enough diesel to leave its truck’s expected consumption. The truck’s own figure is on its fleet record.') }}"
            />
        @else
            <x-ui.table :caption="__('Consumption variances, newest first — the trip’s litres per 100 km against the truck’s expected figure')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Trip') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Distance') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Litres') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('L / 100 km') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Expected') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Variance') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Load') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Detected') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    @if ($this->canDecideVariance())
                        <x-ui.table.th>{{ __('Decision') }}</x-ui.table.th>
                    @endif
                </x-slot>

                @foreach ($this->variances as $variance)
                    <tr wire:key="variance-{{ $variance->id }}">
                        <x-ui.table.td nowrap>
                            <a href="{{ route('operations.trip', $variance->trip_id) }}" class="fg-identifier text-sm text-accent">{{ $variance->trip?->trip_no ?? '—' }}</a>
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $variance->truck?->label() ?? __('Truck #:id', ['id' => $variance->truck_id]) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ number_format($variance->distance_km) }} km</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $variance->litresMajor() }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $variance->litres_per_100_10_2 }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $variance->expected_per_100_10_2 }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $variance->varianceLabel() }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $variance->tonnes_carried_10_2 }} t</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $variance->detected_at?->format('d M Y') ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td><x-ui.badge :tone="$variance->state->tone()">{{ $variance->state->label() }}</x-ui.badge></x-ui.table.td>
                        @if ($this->canDecideVariance())
                            <x-ui.table.td>
                                @if ($variance->isOpen())
                                    <x-ui.button size="sm" variant="outline" wire:click="startVariance({{ $variance->id }})">{{ __('Decide') }}</x-ui.button>
                                @else
                                    <span class="text-xs text-fg-ink-muted">{{ $variance->acknowledgement_note }}</span>
                                @endif
                            </x-ui.table.td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        @error('variance')
            <x-ui.alert tone="danger" :title="__('Refused')" class="mt-4">{{ $message }}</x-ui.alert>
        @enderror

        @if ($this->variances->isNotEmpty())
            <div class="mt-4">
                {{ $this->variances->links() }}
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

        <x-ui.dialog name="bulk-tank" :title="__('Open a bulk tank')">
            <form wire:submit="addBulkTank" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="bulkTankName" wire:model="bulkTankName" :label="__('Name')" placeholder="{{ __('Yard tank 1') }}" />
                    <x-ui.input name="bulkTankCapacity" wire:model="bulkTankCapacity" numeric :label="__('Capacity in litres')" :hint="__('A delivery past this figure is flagged, not refused (§7 R7).')" />
                </div>

                <p class="text-xs text-fg-ink-muted">
                    {{ __('A tank opens empty and worth nothing. Its stock only moves through a delivery, an issue to a trip, or a dip — there is no way to open one holding fuel nobody can account for.') }}
                </p>

                @error('bulkTank')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Open the tank') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-bulk-tank')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="bulk-dip" :title="__('Reconcile a bulk tank')">
            <form wire:submit="reconcile" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.select name="dipTankId" id="dip-tank" wire:model="dipTankId" :label="__('Tank')">
                        <option value="">{{ __('Choose a tank') }}</option>
                        @foreach ($this->bulkTanks as $tank)
                            <option value="{{ $tank->id }}">{{ $tank->name }} · {{ $this->tankSummary($tank) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input name="dipLitres" wire:model="dipLitres" numeric :label="__('Litres on the gauge')" placeholder="{{ __('3 500.00') }}" />

                    <x-ui.input name="dipDate" wire:model="dipDate" :label="__('Counted on')" type="date" />

                    <x-ui.input name="dipNote" wire:model="dipNote" :label="__('Note (optional)')" />
                </div>

                <p class="text-xs text-fg-ink-muted">
                    {{ __('The difference between the gauge and the book is valued at the tank’s average and posted to 5110 Fuel stock variance — short is a debit, a gain is a credit. A dip that agrees is still recorded.') }}
                </p>

                @error('dip')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Record the dip') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-bulk-dip')">{{ __('Cancel') }}</x-ui.button>
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

                    <div class="sm:col-span-2">
                        <x-ui.select name="slipBulkTankId" id="slip-bulk-tank" wire:model.live="slipBulkTankId" :label="__('Where the diesel went')" :hint="__('A slip is drained by trips; a delivery into a tank becomes stock and posts Dr 1180 / Cr 2110 on delivery.')">
                            <option value="">{{ __('Into slips the trucks draw from') }}</option>
                            @foreach ($this->bulkTanks as $tank)
                                <option value="{{ $tank->id }}">{{ $tank->name }} · {{ $tank->capacity_litres }} L</option>
                            @endforeach
                        </x-ui.select>
                    </div>

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
                    @if (trim((string) $this->slipBulkTankId) !== '')
                        {{ __('This delivery enters stock: the amount posts Dr 1180 Bulk fuel stock / Cr 2110 Supplier payable, and the tank’s moving average becomes what a trip pays per litre.') }}
                    @else
                        {{ __('The amount is litres × price, computed here — the register refuses a slip whose figures disagree. The slip is posted to the ledger with the supplier bill (§6.8, Phase 5).') }}
                    @endif
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

    @if ($this->canDecideVariance())
        <x-ui.dialog name="fuel-variance" :title="__('Decide this variance')">
            <form wire:submit="decideVariance" class="grid gap-4 p-5">
                <x-ui.select name="varianceDecision" id="variance-decision" wire:model="varianceDecision" :label="__('Decision')">
                    <option value="acknowledged">{{ __('Acknowledge — the reason is known and acceptable') }}</option>
                    <option value="disputed">{{ __('Dispute — the figures themselves are doubted') }}</option>
                    <option value="resolved">{{ __('Resolve — the matter is closed') }}</option>
                </x-ui.select>

                <x-ui.input name="varianceNote" wire:model="varianceNote" :label="__('What settled it')" :hint="__('Stored on the row and in the operational trail. A note is required.')" />

                <p class="text-xs text-fg-ink-muted">
                    {{ __('Resolving closes the fuel-variance alert this row raised. Acknowledging and disputing leave it live until somebody resolves it.') }}
                </p>

                @error('variance')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Record the decision') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-fuel-variance')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
