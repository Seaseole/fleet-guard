<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\Actions\RecordKioskFuelIssue;
use App\Domain\Fuel\Data\KioskFuelDto;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Fuel\Support\FuelPriceList;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\RedeemKioskToken;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\TripAccessScope;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * §9.6 — the driver's fuel screen. One page, no login, one trip, the same write door the office uses.
 *
 * The fields are what the slip in the driver's hand actually says — the station, the litres, the price
 * per litre — plus the tank the diesel went into and the odometer the truck is showing. The price is
 * typed rather than picked: a cash slip's price is the pump's number that day, and the slip the office
 * later reconciles has to agree with it to the cent.
 *
 * There is no "save draft" and no editing. A submit goes through
 * {@see RecordKioskFuelIssue}, which records the slip, issues its litres onto the trip, posts the
 * 5100 cost and moves the odometer in one transaction; a retry after a dropped signal carries the
 * same `client_uuid` and returns the row it made instead of buying the diesel twice.
 */
new #[Layout('layouts::auth')] #[Title('Fuel on the road')] class extends Component
{
    public string $tankId = '';

    public string $stationId = '';

    public string $litres = '';

    public string $price = '';

    public string $odometer = '';

    public string $invoiceNo = '';

    public string $note = '';

    public string $pin = '';

    /**
     * The page's own link, held so a Livewire update can re-open it.
     *
     * `/livewire/update` does not run the `kiosk` route middleware, so the request attributes and the
     * company context the initial GET established are gone by the first submit. The token is not a
     * secret beyond the URL that already carries it, and the snapshot is signed; these two values let
     * {@see token()} redeem the same link again on every update instead of trusting a request attribute
     * no one set.
     */
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $secretKey = '';

    /** Minted per page load and refreshed after each accepted issue — see the class note on the retry. */
    public string $pendingUuid = '';

    /** The settings price for this trip's fuel type, restored after each accepted slip. */
    #[Locked]
    public string $defaultPrice = '';

    public bool $sent = false;

    public string $lastLine = '';

    public function mount(FuelPriceList $prices): void
    {
        $this->pendingUuid = (string) Str::uuid();
        $this->companyId = (string) request()->route('company');
        $this->secretKey = (string) request()->route('secret');

        $trip = $this->tripFor();

        if ($trip?->truck_id !== null) {
            $truck = Truck::query()->find($trip->truck_id);

            if ($truck !== null) {
                $this->odometer = (string) $truck->odometer_km;
                $this->price = $prices->input($truck->fuel_type);
                $this->defaultPrice = $this->price;
            }
        }

        $tanks = $this->tanks();

        if (count($tanks) === 1) {
            $this->tankId = (string) $tanks[0]->id;
        }
    }

    public function save(RecordKioskFuelIssue $record): void
    {
        // Resolved first: on an update this is what forces the company context the station rule reads.
        $token = $this->token();

        if ($token === null || ! $token->hasScope(TripAccessScope::Fuel)) {
            $this->addError('form', __('This link does not open fuel capture.'));

            return;
        }

        $this->validate([
            'tankId' => ['required', 'integer'],
            'stationId' => ['nullable', 'integer', Rule::exists('fuel_stations', 'id')->where('company_id', CurrentCompany::id())],
            'litres' => ['required', 'string', 'regex:/\A\d{1,7}([.,]\d{1,2})?\z/'],
            'price' => ['required', 'string', 'regex:/\A\d{1,7}([.,]\d{1,2})?\z/'],
            'odometer' => ['required', 'integer', 'min:0', 'max:9999999'],
            'invoiceNo' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:120'],
            'pin' => ['required', 'string', 'size:6', 'regex:/\A\d{6}\z/'],
        ]);

        try {
            ($record)(
                $token,
                $this->secret(),
                $this->pin,
                new KioskFuelDto(
                    tankId: (int) $this->tankId,
                    litresX100: $this->scaledHundredths($this->litres),
                    pricePerLitreMinor: $this->scaledHundredths($this->price),
                    odometerKm: (int) $this->odometer,
                    occurredOn: Carbon::now(CurrentCompany::timezone()),
                    stationId: trim($this->stationId) === '' ? null : (int) $this->stationId,
                    invoiceNo: $this->invoiceNo,
                    note: $this->note,
                ),
                $this->pendingUuid,
            );
        } catch (Throwable $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->sent = true;
        $this->lastLine = __(':litres L recorded — the office sees it against this trip.', ['litres' => $this->litres]);

        $this->pendingUuid = (string) Str::uuid();
        $this->reset(['litres', 'price', 'invoiceNo', 'note', 'pin']);
        $this->price = $this->defaultPrice;
        unset($this->issues);

        $this->dispatch('toast', message: __('Fuel recorded and posted.'), kind: 'success');
    }

    /** The trip this link is bound to — the only trip this page can speak about (§9.6). */
    public function tripFor(): ?Trip
    {
        return $this->token()?->trip;
    }

    public function truckLabel(): string
    {
        $trip = $this->tripFor();

        if ($trip?->truck_id === null) {
            return __('this trip’s truck');
        }

        return Truck::query()->whereKey($trip->truck_id)->first()?->label() ?? __('this trip’s truck');
    }

    /** The product this trip's truck takes — the type the slip will record. */
    public function fuelTypeLabel(): string
    {
        $trip = $this->tripFor();

        if ($trip?->truck_id === null) {
            return __('—');
        }

        return Truck::query()->whereKey($trip->truck_id)->first()?->fuel_type->label() ?? __('—');
    }

    /** §10.2: a closed, cancelled or aborted trip has costs that are final rather than editable. */
    public function costsAreFrozen(): bool
    {
        $trip = $this->tripFor();

        return $trip === null
            || $trip->costs_frozen
            || in_array($trip->status, [TripStatus::Cancelled, TripStatus::Aborted], true);
    }

    public function canCapture(): bool
    {
        $token = $this->token();

        return $token !== null
            && $token->hasScope(TripAccessScope::Fuel)
            && $this->tripFor() !== null
            && $this->tripFor()?->truck_id !== null
            && ! $this->costsAreFrozen()
            && $this->tanks() !== [];
    }

    /** The page's own door — asked in the view, so a `pod`-only link is not offered a form it cannot use. */
    public function opensFuel(): bool
    {
        return $this->token()?->hasScope(TripAccessScope::Fuel) ?? false;
    }

    public function opensPod(): bool
    {
        return $this->token()?->hasScope(TripAccessScope::Pod) ?? false;
    }

    public function podUrl(): string
    {
        return route('kiosk.capture', [
            'company' => CurrentCompany::id(),
            'secret' => $this->secret(),
        ]);
    }

    /** @return list<FuelTank> */
    #[Computed]
    public function tanks(): array
    {
        $trip = $this->tripFor();

        if ($trip?->truck_id === null) {
            return [];
        }

        return FuelTank::query()
            ->where('truck_id', $trip->truck_id)
            ->where('active', true)
            ->orderBy('kind')
            ->get()
            ->all();
    }

    /** @return list<FuelStation> */
    #[Computed]
    public function stations(): array
    {
        return FuelStation::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** @return list<FuelIssue> */
    #[Computed]
    public function issues(): array
    {
        $trip = $this->tripFor();

        if ($trip === null) {
            return [];
        }

        return FuelIssue::query()
            ->with('tank')
            ->where('trip_id', $trip->id)
            ->where('status', 'posted')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->all();
    }

    public function litresLabel(int $litresX100): string
    {
        return number_format($litresX100 / 100, 2, '.', '');
    }

    /** "19.75" → 1975, without ever building a float (§20.4). */
    private function scaledHundredths(string $input): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__('Enter a number with at most two decimals.'));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    private function token(): ?TripAccessToken
    {
        $token = request()->attributes->get('kioskToken');

        if ($token instanceof TripAccessToken) {
            return $token;
        }

        if ($this->companyId === '' || $this->secretKey === '') {
            return null;
        }

        try {
            $token = app(RedeemKioskToken::class)->fromSecret((int) $this->companyId, $this->secretKey, (string) request()->ip());
        } catch (\InvalidArgumentException) {
            return null;
        }

        // The rest of this request (render included) reads the token through the same attribute the
        // route middleware sets, so the link is redeemed once per update, not once per property read.
        request()->attributes->set('kioskToken', $token);
        request()->attributes->set('kioskSecret', $this->secretKey);

        return $token;
    }

    private function secret(): string
    {
        return (string) request()->attributes->get('kioskSecret');
    }
}; ?>

<div class="mx-auto grid w-full max-w-xl gap-5">
    @php($trip = $this->tripFor())

    <header class="grid gap-1">
        <x-ui.heading size="lg" level="1">{{ __('Fuel on the road') }}</x-ui.heading>

        @if ($trip !== null)
            <p class="text-sm text-fg-ink-secondary">
                {{ __('Trip :trip · :truck', ['trip' => $trip->trip_no, 'truck' => $this->truckLabel()]) }}
            </p>
        @endif
    </header>

    @if ($trip === null)
        <x-ui.alert tone="danger" :title="__('This link is closed')">
            {{ __('Ask the dispatcher for a new one.') }}
        </x-ui.alert>
    @elseif (! $this->opensFuel())
        <x-ui.alert tone="warning" :title="__('This link does not open fuel capture')">
            {{ __('The dispatcher chooses what a driver link may capture. Ask for one that names fuel.') }}
        </x-ui.alert>

        @if ($this->opensPod())
            <x-ui.button variant="outline" :href="$this->podUrl()">
                {{ __('Go to proof of delivery') }}
            </x-ui.button>
        @endif
    @else
        @if ($this->sent)
            <x-ui.alert tone="success" :title="__('Recorded')">
                {{ $this->lastLine }}
            </x-ui.alert>
        @endif

        @if ($this->costsAreFrozen())
            <x-ui.alert tone="info" :title="__('Costs are final')">
                {{ __('This trip is closed, cancelled or aborted, so no more fuel may be issued. Ask the office.') }}
            </x-ui.alert>
        @elseif ($trip->truck_id === null)
            <x-ui.alert tone="info" :title="__('No truck named')">
                {{ __('The trip has no truck, so there is no tank to fill. Ask the dispatcher.') }}
            </x-ui.alert>
        @elseif ($this->tanks === [])
            <x-ui.alert tone="info" :title="__('No tank is fitted')">
                {{ __('The office has not fitted a tank to this truck yet.') }}
            </x-ui.alert>
        @else
            <x-ui.card :title="__('Record what the pump gave you')">
                <form class="grid gap-4" wire:submit="save">
                    <x-ui.select name="tankId" id="kiosk-fuel-tank" wire:model="tankId" :label="__('Tank')">
                        <option value="">{{ __('Choose a tank') }}</option>
                        @foreach ($this->tanks as $tank)
                            <option value="{{ $tank->id }}">{{ $tank->kind->label() }} · {{ $tank->capacity_litres }} L</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="stationId" id="kiosk-fuel-station" wire:model="stationId" :label="__('Station')">
                        <option value="">{{ __('Not listed') }}</option>
                        @foreach ($this->stations as $station)
                            <option value="{{ $station->id }}">{{ $station->label() }}</option>
                        @endforeach
                    </x-ui.select>

                    @if ($this->stations === [])
                        <p class="text-xs text-fg-ink-muted">
                            {{ __('The office has not registered any stations yet. You can still record the fuel; ask the office to add the station later.') }}
                        </p>
                    @endif

                    <p class="text-xs text-fg-ink-muted">
                        {{ __('This truck takes :fuel. The slip records that, and the price below starts at the settings figure for it.', ['fuel' => $this->fuelTypeLabel()]) }}
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input name="litres" wire:model="litres" numeric :label="__('Litres')" placeholder="{{ __('250.00') }}" required />
                        <x-ui.input name="price" wire:model="price" numeric :label="__('Price per litre')" :hint="__('Starts at the :fuel price in application settings; the receipt’s figure is what gets recorded.', ['fuel' => $this->fuelTypeLabel()])" placeholder="{{ __('19.75') }}" required />
                    </div>

                    <x-ui.input name="odometer" wire:model="odometer" numeric :label="__('Odometer reading')" :hint="__('The figure on the dashboard now.')" required />

                    <x-ui.input name="invoiceNo" wire:model="invoiceNo" :label="__('Receipt number (optional)')" />
                    <x-ui.input name="note" wire:model="note" :label="__('Note (optional)')" />

                    <x-ui.input name="pin" wire:model="pin" :label="__('6-digit PIN from the dispatcher')" type="text" inputmode="numeric" autocomplete="one-time-code" required />

                    @error('form')
                        <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                    @enderror

                    <x-ui.button type="submit" size="lg" class="w-full">{{ __('Record the fuel') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($this->issues !== [])
            <section class="grid gap-3">
                <x-ui.subheading>{{ __('Fuel already on this trip') }}</x-ui.subheading>

                <x-ui.table :caption="__('The most recent fuel issued to this trip, newest first')">
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Tank') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Litres') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('When') }}</x-ui.table.th>
                    </x-slot>

                    @foreach ($this->issues as $issued)
                        <tr wire:key="kiosk-fuel-{{ $issued->id }}">
                            <x-ui.table.td>{{ $issued->tank?->kind->label() ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $issued->litresMajor() }}</x-ui.table.td>
                            <x-ui.table.td>{{ $issued->created_at?->diffForHumans() }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </section>
        @endif

        @if ($this->opensPod())
            <x-ui.button variant="outline" :href="$this->podUrl()">
                {{ __('Go to proof of delivery') }}
            </x-ui.button>
        @endif
    @endif
</div>
