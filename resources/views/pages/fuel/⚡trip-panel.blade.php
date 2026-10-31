<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\Actions\RecordFuelIssue;
use App\Domain\Fuel\Actions\ReverseFuelIssue;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Fuel\Models\FuelVariance;
use App\Domain\Fuel\Support\FuelConsumption;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A trip's fuel panel (§4.3, §10.9): what this trip's truck has been given, and the one form that
 * gives it more.
 *
 * It is a component of its own rather than another section of the trip page because fuel is a
 * module with its own seat (`fuel.record`) and its own machine; the trip page should not grow a
 * second set of validation rules for money it does not own. The trip is locked — a client that
 * edits the id is not filling somebody else's trip — and every write goes through
 * `RecordFuelIssue`, which composes the trip cost and the odometer reading in one transaction.
 *
 * The slip list only offers slips with litres left on them. An exhausted slip is not a choice the
 * Action would accept, so the form does not pretend it is one.
 */
new class extends Component {
    #[Locked]
    public Trip $trip;

    public string $tankId = '';

    public string $source = 'pump_card';

    public string $purchaseId = '';

    public string $bulkTankId = '';

    public string $litres = '';

    public string $odometer = '';

    public string $occurredOn = '';

    public string $note = '';

    public string $reversalIssueId = '';

    public string $reversalReason = '';

    public function mount(Trip $trip): void
    {
        $this->trip = $trip;
        $this->occurredOn = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->odometer = $trip->truck_id === null
            ? ''
            : (string) (Truck::query()->whereKey($trip->truck_id)->value('odometer_km') ?? '');
    }

    public function canSeeFuel(): bool
    {
        return Gate::allows('fuel.view') || Gate::allows('fuel.record');
    }

    public function canRecordFuel(): bool
    {
        return Gate::allows('fuel.record')
            && Gate::allows('expenses.create')
            && $this->trip->truck_id !== null
            && ! $this->costsAreFrozen()
            && $this->tanks !== [];
    }

    /** §10.9's correction seat: reversing a posted issue is an operations decision (§9.3). */
    public function canCorrectFuel(): bool
    {
        return Gate::allows('fuel.correct');
    }

    /** §10.2: a closed trip, or one that never left, has costs that are final rather than editable. */
    public function costsAreFrozen(): bool
    {
        return $this->trip->costs_frozen
            || in_array($this->trip->status, [TripStatus::Cancelled, TripStatus::Aborted], true);
    }

    public function record(RecordFuelIssue $issue): void
    {
        abort_unless($this->canRecordFuel(), 403);

        $validated = $this->validate([
            'tankId' => ['required', 'integer'],
            'source' => ['required', Rule::in([FuelIssueSource::PumpCard->value, FuelIssueSource::BulkTank->value])],
            'purchaseId' => ['nullable', 'string', 'required_if:source,'.FuelIssueSource::PumpCard->value],
            'bulkTankId' => ['nullable', 'integer', 'required_if:source,'.FuelIssueSource::BulkTank->value],
            'litres' => ['required', 'string'],
            'odometer' => ['required', 'integer', 'min:0', 'max:9999999'],
            'occurredOn' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $source = FuelIssueSource::from($validated['source']);

        try {
            $created = $issue($this->trip, new FuelIssueDto(
                tankId: (int) $validated['tankId'],
                litresX100: $this->scaledHundredths($validated['litres']),
                odometerKm: (int) $validated['odometer'],
                occurredOn: Carbon::parse($validated['occurredOn']),
                purchaseId: $source === FuelIssueSource::BulkTank ? null : $validated['purchaseId'],
                source: $source,
                note: $validated['note'] ?? null,
                bulkTankId: $source === FuelIssueSource::BulkTank ? (int) $validated['bulkTankId'] : null,
            ), (int) auth()->id());
        } catch (InvalidArgumentException $refused) {
            $this->addError('fuel', $refused->getMessage());

            return;
        }

        $this->reset(['litres', 'note']);
        $this->occurredOn = Carbon::now(CurrentCompany::timezone())->toDateString();
        unset($this->issues, $this->slips, $this->bulkTanks);

        $this->dispatch('close-trip-fuel');
        $this->dispatch('trip-fuel-recorded');
        $this->dispatch('toast', message: __(':litres L issued — the cost is posted.', ['litres' => $created->litresMajor()]), kind: 'success');
    }

    public function startReversal(string $issueId): void
    {
        abort_unless($this->canCorrectFuel(), 403);

        $this->reversalIssueId = $issueId;
        $this->reversalReason = '';
        $this->resetErrorBag('reversal');
        $this->dispatch('open-fuel-issue-reversal');
    }

    public function reverse(ReverseFuelIssue $reverse): void
    {
        abort_unless($this->canCorrectFuel(), 403);

        $validated = $this->validate([
            'reversalIssueId' => ['required', 'string'],
            'reversalReason' => ['required', 'string', 'max:160'],
        ]);

        $issue = FuelIssue::query()
            ->whereKey($validated['reversalIssueId'])
            ->where('trip_id', $this->trip->id)
            ->firstOrFail();

        try {
            $reverse($issue, $validated['reversalReason'], (int) auth()->id());
        } catch (InvalidArgumentException $refused) {
            $this->addError('reversal', $refused->getMessage());

            return;
        }

        unset($this->issues, $this->consumption, $this->variance);
        $this->reset(['reversalIssueId', 'reversalReason']);
        $this->dispatch('close-fuel-issue-reversal');
        $this->dispatch('trip-fuel-recorded');
        $this->dispatch('toast', message: __('Issue reversed — the mirror entry is posted and the stock is back.'), kind: 'success');
    }

    /** "45.32" → 4532, without ever building a float (§20.4). */
    private function scaledHundredths(string $input): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__('Litres must be a number with at most two decimals.'));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /** @return list<FuelIssue> */
    #[Computed]
    public function issues(): array
    {
        return FuelIssue::query()
            ->with(['tank', 'purchase:id,invoice_no', 'bulkTank:id,name'])
            ->where('trip_id', $this->trip->id)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /** The variance row raised when this trip completed, if the figures crossed the threshold. */
    #[Computed]
    public function variance(): ?FuelVariance
    {
        return FuelVariance::query()->where('trip_id', $this->trip->id)->first();
    }

    /**
     * What the issued litres work out to per 100 km while the trip has a distance.
     *
     * Reversed issues are excluded: a reversal says the diesel never went on this trip, so counting
     * it would keep the consumption figure at a number the ledger has already forgiven.
     *
     * @return array{distance_km: int, litres_x100: int, actual_x100: int, expected_x100: int|null, variance_pct_x100: int|null}|null
     */
    #[Computed]
    public function consumption(): ?array
    {
        $distanceKm = (int) round((float) $this->trip->distance_km_10_2);

        if ($distanceKm <= 0) {
            return null;
        }

        $litresX100 = (int) FuelIssue::query()
            ->where('trip_id', $this->trip->id)
            ->where('status', FuelIssueStatus::Posted->value)
            ->sum('litres_x100');

        if ($litresX100 <= 0) {
            return null;
        }

        $expected = Truck::query()->whereKey($this->trip->truck_id)->value('expected_consumption_per_100');

        if ($expected === null || (float) $expected <= 0) {
            return [
                'distance_km' => $distanceKm,
                'litres_x100' => $litresX100,
                'actual_x100' => intdiv($litresX100 * 100 + intdiv($distanceKm, 2), $distanceKm),
                'expected_x100' => null,
                'variance_pct_x100' => null,
            ];
        }

        $figures = FuelConsumption::compare($litresX100, $distanceKm, (float) $expected);

        return [
            'distance_km' => $distanceKm,
            'litres_x100' => $litresX100,
            'actual_x100' => $figures['actual_x100'],
            'expected_x100' => $figures['expected_x100'],
            'variance_pct_x100' => $figures['variance_pct_x100'],
        ];
    }

    /** @return list<FuelTank> */
    #[Computed]
    public function tanks(): array
    {
        return FuelTank::query()
            ->where('truck_id', $this->trip->truck_id)
            ->where('active', true)
            ->orderBy('kind')
            ->get()
            ->all();
    }

    /** @return list<FuelPurchase> */
    #[Computed]
    public function slips(): array
    {
        return FuelPurchase::query()
            ->with('station')
            ->orderByDesc('purchased_on')
            ->get()
            ->filter(static fn (FuelPurchase $purchase): bool => $purchase->litresRemainingX100() > 0)
            ->values()
            ->all();
    }

    /** @return list<BulkTank> */
    #[Computed]
    public function bulkTanks(): array
    {
        return BulkTank::query()
            ->where('active', true)
            ->where('stock_litres_x100', '>', 0)
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function tankStock(BulkTank $tank): string
    {
        return $tank->stockLitres();
    }

    public function tankAverage(BulkTank $tank): string
    {
        return $tank->averageMoney()->format(CurrentCompany::model()->base_currency_code);
    }

    public function remaining(FuelPurchase $purchase): string
    {
        return number_format($purchase->litresRemainingX100() / 100, 2, '.', '');
    }

    public function money(int $minorUnits): string
    {
        return Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)
            ->format(CurrentCompany::model()->base_currency_code);
    }

    public function litresLabel(int $litresX100): string
    {
        return number_format($litresX100 / 100, 2, '.', '');
    }

    public function rateLabel(int $per100X100): string
    {
        return FuelConsumption::litresLabel($per100X100);
    }

    public function varianceLabel(int $pctX100): string
    {
        return FuelConsumption::varianceLabel($pctX100);
    }

    public function truckLabel(): string
    {
        return Truck::query()->whereKey($this->trip->truck_id)->first()?->label() ?? __('this trip’s truck');
    }
}; ?>

{{--
    The root element is unconditional on purpose: Livewire reads a nested component's tag name from the
    first element it renders, and a `@if` around the <section> puts a block marker there instead, which
    later renders reject as an invalid child tag. The condition lives inside the wrapper.
--}}
<div class="grid gap-5">
    @if ($this->canSeeFuel())
        <section class="grid gap-5 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="grid gap-1">
                    <x-ui.subheading>{{ __('Fuel') }}</x-ui.subheading>
                    <x-ui.text size="sm" color="muted">{{ __('Diesel issued to :truck on this trip. An issue posts a trip cost and moves the odometer.', ['truck' => $this->truckLabel()]) }}</x-ui.text>
                </div>

                @if ($this->canRecordFuel())
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-trip-fuel')">{{ __('Record fuel') }}</x-ui.button>
                @endif
            </div>

            @php($consumption = $this->consumption)

            @if ($consumption !== null)
                <div class="grid gap-2 rounded-fg-lg border border-fg-line bg-fg-canvas p-3">
                    <span class="text-sm text-fg-ink">
                        {{ __(':litres L over :km km — :rate L / 100 km.', [
                            'litres' => $this->litresLabel($consumption['litres_x100']),
                            'km' => number_format($consumption['distance_km']),
                            'rate' => $this->rateLabel($consumption['actual_x100']),
                        ]) }}
                    </span>

                    @if ($consumption['expected_x100'] !== null)
                        <span class="text-xs text-fg-ink-muted">
                            {{ __('The truck expects :expected L / 100 km, so this trip is :variance% against it.', [
                                'expected' => $this->rateLabel($consumption['expected_x100']),
                                'variance' => $this->varianceLabel($consumption['variance_pct_x100']),
                            ]) }}
                        </span>
                    @else
                        <span class="text-xs text-fg-ink-muted">
                            {{ __('This truck has no expected consumption on its record, so there is nothing to compare the figure against.') }}
                        </span>
                    @endif

                    @if ($this->variance !== null)
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="$this->variance->state->tone()">{{ __('Variance') }} · {{ $this->variance->state->label() }}</x-ui.badge>
                            @if ($this->variance->acknowledgement_note !== null)
                                <span class="text-xs text-fg-ink-muted">{{ $this->variance->acknowledgement_note }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            @if ($this->issues === [])
                <x-ui.text>{{ __('No fuel has been issued to this trip yet.') }}</x-ui.text>
            @else
                <x-ui.table :caption="__('Fuel issued on this trip, newest first, with the state of each issue')">
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Tank') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Source') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Litres') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Cost') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                        @if ($this->canCorrectFuel())
                            <x-ui.table.th>{{ __('Correction') }}</x-ui.table.th>
                        @endif
                    </x-slot>

                    @foreach ($this->issues as $issued)
                        <tr wire:key="trip-fuel-{{ $issued->id }}">
                            <x-ui.table.td>{{ $issued->tank?->kind->label() ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td>{{ $issued->purchase?->invoice_no ?? $issued->bulkTank?->name ?? __('pump card') }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $issued->litresMajor() }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $issued->odometer_km }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $this->money($issued->cost_base) }}</x-ui.table.td>
                            <x-ui.table.td><x-ui.badge :tone="$issued->status->tone()">{{ $issued->status->label() }}</x-ui.badge></x-ui.table.td>
                            @if ($this->canCorrectFuel())
                                <x-ui.table.td>
                                    @if ($issued->status->value === 'posted')
                                        <x-ui.button size="sm" variant="outline" wire:click="startReversal('{{ $issued->id }}')">{{ __('Reverse') }}</x-ui.button>
                                    @elseif ($issued->status->value === 'reversed')
                                        <span class="text-xs text-fg-ink-muted">{{ $issued->reversal_reason }}</span>
                                    @else
                                        <span class="text-xs text-fg-ink-muted">—</span>
                                    @endif
                                </x-ui.table.td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>

                @foreach ($this->issues as $issued)
                    @if ($issued->exception_reason !== null)
                        <x-ui.alert tone="warning" :title="__('Look at this figure')">{{ $issued->exception_reason }}</x-ui.alert>
                    @endif
                @endforeach
            @endif

            @error('fuel')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            @error('reversal')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            @if ($this->costsAreFrozen() && $this->trip->truck_id !== null)
                <x-ui.alert tone="info" :title="__('Costs are final')">
                    {{ __('This trip is closed, cancelled or aborted, so no fuel may be issued. Re-open it, or correct the ledger with a reversing entry (§6.4).') }}
                </x-ui.alert>
            @elseif ($this->trip->truck_id !== null && $this->tanks === [])
                <x-ui.alert tone="info" :title="__('No tank is fitted')">
                    {{ __('A truck with no tank has nowhere to take fuel into. Fit one on the fuel register, taking its capacity from the truck.') }}
                </x-ui.alert>
            @endif

            @if ($this->canRecordFuel())
                <x-ui.dialog name="trip-fuel" :title="__('Issue fuel on this trip')" max-width="2xl">
                    <form wire:submit="record" class="grid gap-4 p-5">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.select name="tankId" id="trip-fuel-tank" wire:model="tankId" :label="__('Tank')">
                                <option value="">{{ __('Choose a tank') }}</option>
                                @foreach ($this->tanks as $tank)
                                    <option value="{{ $tank->id }}">{{ $tank->kind->label() }} · {{ $tank->capacity_litres }} L</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select name="source" id="trip-fuel-source" wire:model.live="source" :label="__('From')">
                                <option value="pump_card">{{ __('A pump slip') }}</option>
                                <option value="bulk_tank">{{ __('The bulk tank') }}</option>
                            </x-ui.select>

                            @if ($this->source === 'bulk_tank')
                                <x-ui.select name="bulkTankId" id="trip-fuel-bulk-tank" wire:model="bulkTankId" :label="__('Bulk tank')">
                                    <option value="">{{ __('Choose a tank') }}</option>
                                    @foreach ($this->bulkTanks as $bulkTank)
                                        <option value="{{ $bulkTank->id }}">{{ $bulkTank->name }} · {{ $this->tankStock($bulkTank) }} L at {{ $this->tankAverage($bulkTank) }}</option>
                                    @endforeach
                                </x-ui.select>
                            @else
                                <x-ui.select name="purchaseId" id="trip-fuel-slip" wire:model="purchaseId" :label="__('Slip it comes from')">
                                    <option value="">{{ __('Choose a slip') }}</option>
                                    @foreach ($this->slips as $slip)
                                        <option value="{{ $slip->id }}">{{ $slip->invoice_no ?? substr($slip->id, -8) }} · {{ $slip->fuel_type->label() }} · {{ $this->remaining($slip) }} L left</option>
                                    @endforeach
                                </x-ui.select>
                            @endif

                            <x-ui.input name="litres" wire:model="litres" numeric :label="__('Litres')" placeholder="{{ __('250.00') }}" />

                            <x-ui.input name="odometer" wire:model="odometer" numeric :label="__('Odometer reading')" :hint="__('The truck’s current figure; a reading below its projection is kept and marked suspect.')" />

                            <x-ui.input name="occurredOn" wire:model="occurredOn" :label="__('When')" type="date" />

                            <x-ui.input name="note" wire:model="note" :label="__('Note (optional)')" />
                        </div>

                        <p class="text-xs text-fg-ink-muted">
                            @if ($this->source === 'bulk_tank')
                                {{ __('The cost is the bulk tank’s moving average at this moment, frozen onto the issue. It posts to 5100 for this trip, credited against 1180 Bulk fuel stock — the diesel was paid for when it was delivered.') }}
                            @else
                                {{ __('The cost is the slip’s price at the slip’s rate, frozen onto the issue. It posts to 5100 for this trip, on the supplier account a pump card is billed to.') }}
                            @endif
                        </p>

                        @error('fuel')
                            <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                        @enderror

                        <div class="flex items-center justify-between gap-3">
                            <x-ui.button type="submit">{{ __('Issue and post') }}</x-ui.button>
                            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-trip-fuel')">{{ __('Cancel') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.dialog>
            @endif

            @if ($this->canCorrectFuel())
                <x-ui.dialog name="fuel-issue-reversal" :title="__('Reverse this fuel issue')">
                    <form wire:submit="reverse" class="grid gap-4 p-5">
                        <p class="text-sm text-fg-ink-muted">
                            {{ __('The issue is not edited (§10.9). A mirror journal entry nets the cost, a bulk tank takes its litres and money back, the slip’s litres become available again, and the issue is marked reversed with your reason in the trail.') }}
                        </p>

                        <x-ui.input name="reversalReason" wire:model="reversalReason" :label="__('Reason for the reversal')" :hint="__('Required — stored on the issue and in the operational trail.')" />

                        @error('reversal')
                            <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                        @enderror

                        <div class="flex items-center justify-between gap-3">
                            <x-ui.button type="submit">{{ __('Reverse and post the mirror') }}</x-ui.button>
                            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-fuel-issue-reversal')">{{ __('Cancel') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.dialog>
            @endif
        </section>
    @endif
</div>
