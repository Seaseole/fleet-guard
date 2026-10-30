<?php

declare(strict_types=1);

use App\Domain\Telematics\Data\LiveVehicle;
use App\Domain\Telematics\Models\GpsPosition;
use App\Domain\Telematics\Queries\LiveMap;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The live map (§3.2, §14.3, §15.2 slice 2g).
 *
 * Two reads, and only two: one row per truck from §14.3's `latest_positions` projection, and one trip's
 * track from the history table when a truck is selected. The projection is what keeps this page cheap as
 * `gps_positions` grows past a million rows — the poll never touches the history except for the trip
 * being looked at.
 *
 * The drawing is an inline SVG on a fixed Southern-Africa lat/lng box rather than Leaflet. That is a
 * deliberate Phase 2 line, not an oversight: §15.2 puts the tiled map (Leaflet/OSM, no key) and the
 * planned-vs-actual overlay in Phase 7, and the transport here is a `wire:poll` at
 * `fleet.telematics.poll_seconds` because §12.2 defers Reverb. What a schematic still has to be is
 * *honest* — every marker is a real stored fix with its own clock on it, and a fix older than
 * `stale_after_minutes` is labelled stale instead of being drawn as though the truck were there now.
 *
 * No money, no margin, no crew details on this screen: `gps.view` is held by seats that §9.3 keeps away
 * from those columns, so there is nothing here to gate at the field layer.
 */
new #[Title('Live map')] class extends Component {
    /** The corridor box the fleet actually drives in: Angola to Mozambique, Tanzania to the Cape. */
    private const LNG_MIN = 12.0;

    private const LNG_MAX = 42.0;

    private const LAT_MAX = -8.0;

    private const LAT_MIN = -36.0;

    private const W = 640;

    private const H = 597;

    #[Url]
    public string $trip = '';

    public function updated(string $property): void
    {
        if ($property === 'trip') {
            unset($this->vehicles, $this->selected, $this->track, $this->polyline, $this->trackSize);
        }
    }

    /** Selecting a truck's trip again clears it, so the track panel is a toggle rather than a trap. */
    public function selectTrip(string $tripId): void
    {
        $this->trip = $this->trip === $tripId ? '' : $tripId;
    }

    public function clearTrip(): void
    {
        $this->trip = '';
    }

    /* ------------------------------------------------------------------ reads */

    /** @return list<LiveVehicle> */
    #[Computed]
    public function vehicles(): array
    {
        return app(LiveMap::class)->vehicles();
    }

    #[Computed]
    public function selected(): ?LiveVehicle
    {
        if ($this->trip === '') {
            return null;
        }

        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->trip?->id === $this->trip) {
                return $vehicle;
            }
        }

        return null;
    }

    /** @return list<GpsPosition> */
    #[Computed]
    public function track(): array
    {
        return $this->trip === '' ? [] : app(LiveMap::class)->track($this->trip);
    }

    #[Computed]
    public function trackSize(): int
    {
        return $this->trip === '' ? 0 : app(LiveMap::class)->trackSize($this->trip);
    }

    /** The selected trip's breadcrumb, as an SVG point list. */
    #[Computed]
    public function polyline(): string
    {
        $points = [];

        foreach ($this->track as $fix) {
            $points[] = sprintf('%.1f,%.1f', $this->x((float) $fix->lng_10_7), $this->y((float) $fix->lat_10_7));
        }

        return implode(' ', $points);
    }

    #[Computed]
    public function staleCount(): int
    {
        return count(array_filter($this->vehicles, static fn (LiveVehicle $v): bool => $v->isStale()));
    }

    /* ------------------------------------------------------------------ drawing */

    public function x(float $lng): float
    {
        $span = self::LNG_MAX - self::LNG_MIN;

        return round(max(0.0, min(1.0, ($lng - self::LNG_MIN) / $span)) * self::W, 1);
    }

    public function y(float $lat): float
    {
        $span = self::LAT_MAX - self::LAT_MIN;

        return round(max(0.0, min(1.0, (self::LAT_MAX - $lat) / $span)) * self::H, 1);
    }

    /** A fix outside the corridor box is clamped onto the edge, and this says so rather than lying. */
    public function offMap(float $lat, float $lng): bool
    {
        return $lat > self::LAT_MAX || $lat < self::LAT_MIN || $lng < self::LNG_MIN || $lng > self::LNG_MAX;
    }

    /** @return list<float> */
    public function gridLng(): array
    {
        return [15.0, 20.0, 25.0, 30.0, 35.0, 40.0];
    }

    /** @return list<float> */
    public function gridLat(): array
    {
        return [-10.0, -15.0, -20.0, -25.0, -30.0, -35.0];
    }

    /* ------------------------------------------------------------------ printing */

    public function at(object $moment): string
    {
        return Carbon::instance($moment)->timezone(config('app.timezone'))->format('d M H:i:s');
    }

    public function fixAt(GpsPosition $fix): string
    {
        return $this->at($fix->fixed_at);
    }

    public function pollSeconds(): int
    {
        return (int) config('fleet.telematics.poll_seconds', 60);
    }
}; ?>

<div class="grid gap-6" wire:poll.{{ $this->pollSeconds() }}s>
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Live map') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Where each truck was last heard from. One row per truck, refreshed every :seconds seconds; the track under a selected trip reads the fix history.', ['seconds' => $this->pollSeconds()]) }}
            </x-ui.text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge tone="info" icon="signal">{{ __(':count truck(s) reporting', ['count' => count($this->vehicles)]) }}</x-ui.badge>

            @if ($this->staleCount > 0)
                <x-ui.badge tone="warning">{{ __(':count stale', ['count' => $this->staleCount]) }}</x-ui.badge>
            @endif
        </div>
    </header>

    @if ($this->vehicles === [])
        <x-ui.empty-state
            icon="signal"
            title="{{ __('No positions yet') }}"
            description="{{ __('No truck has reported a fix. Point a provider feed at the ingest door, or run the simulator while a trip is on the road: php artisan gps:simulate') }}"
        />
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            {{-- The schematic. Phase 7 replaces this surface with tiles; the data behind it does not change. --}}
            <x-ui.card as="section" padding="sm" :title="__('Southern Africa, by coordinate')">
                <svg
                    viewBox="0 0 {{ 640 }} {{ 597 }}"
                    class="h-auto w-full rounded-fg-sm bg-fg-surface-sunk"
                    role="img"
                    aria-label="{{ __('Schematic map of the fleet\'s last known positions') }}"
                >
                    @foreach ($this->gridLng() as $lng)
                        <line x1="{{ $this->x($lng) }}" y1="0" x2="{{ $this->x($lng) }}" y2="597" class="stroke-fg-line" stroke-width="1" />
                        <text x="{{ $this->x($lng) + 4 }}" y="14" class="fill-fg-ink-muted text-[11px]">{{ $lng }}°E</text>
                    @endforeach

                    @foreach ($this->gridLat() as $lat)
                        <line x1="0" y1="{{ $this->y($lat) }}" x2="640" y2="{{ $this->y($lat) }}" class="stroke-fg-line" stroke-width="1" />
                        <text x="4" y="{{ $this->y($lat) - 4 }}" class="fill-fg-ink-muted text-[11px]">{{ abs($lat) }}°S</text>
                    @endforeach

                    @if ($this->polyline !== '')
                        <polyline
                            points="{{ $this->polyline }}"
                            class="stroke-accent"
                            stroke-width="2"
                            fill="none"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                        />
                    @endif

                    @foreach ($this->vehicles as $vehicle)
                        @php
                            $cx = $this->x($vehicle->lng);
                            $cy = $this->y($vehicle->lat);
                            $isCurrent = $vehicle->trip?->id === $this->trip;
                        @endphp
                        <g wire:key="marker-{{ $vehicle->truckId }}">
                            <circle
                                cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $isCurrent ? 9 : 6 }}"
                                class="{{ $vehicle->isStale() ? 'fill-fg-ink-muted' : ($isCurrent ? 'fill-accent' : 'fill-success') }}"
                                stroke="currentColor"
                                stroke-width="1"
                            >
                                <title>{{ $vehicle->truckLabel }} · {{ $vehicle->placeLabel() }} · {{ $vehicle->speedLabel() }} · {{ $this->at($vehicle->fixedAt) }}</title>
                            </circle>
                            <text x="{{ $cx + 11 }}" y="{{ $cy + 4 }}" class="fill-fg-ink text-[12px]">{{ $vehicle->truckLabel }}</text>
                        </g>
                    @endforeach
                </svg>

                <p class="mt-3 text-xs text-fg-ink-muted">
                    {{ __('A schematic, not a street map: markers are plotted straight from latitude and longitude. Tiled maps, geofences and planned-vs-actual arrive in Phase 7.') }}
                </p>
            </x-ui.card>

            <x-ui.card as="section" padding="none" :title="__('Last fix per truck')">
                <x-ui.table>
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Trip') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Last fix') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Speed') }}</x-ui.table.th>
                    </x-slot>

                    @foreach ($this->vehicles as $vehicle)
                        <tr wire:key="vehicle-{{ $vehicle->truckId }}" class="{{ $vehicle->trip?->id === $this->trip ? 'bg-fg-surface-sunk' : '' }}">
                            <x-ui.table.td nowrap>
                                <span class="block text-fg-ink">{{ $vehicle->truckLabel }}</span>
                                <span class="block text-xs text-fg-ink-muted">{{ $vehicle->placeLabel() }}</span>
                            </x-ui.table.td>

                            <x-ui.table.td nowrap>
                                @if ($vehicle->trip !== null)
                                    <a href="{{ route('operations.trip', $vehicle->trip->id) }}" class="font-mono text-sm text-accent">{{ $vehicle->trip->tripNo }}</a>
                                    <span class="block text-xs text-fg-ink-muted">
                                        {{ $vehicle->trip->origin }} → {{ $vehicle->trip->destination }}
                                    </span>
                                @else
                                    <span class="text-xs text-fg-ink-muted">{{ __('not on a trip') }}</span>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td nowrap>
                                <span class="block text-fg-ink">{{ $this->at($vehicle->fixedAt) }}</span>

                                @if ($vehicle->isStale())
                                    <x-ui.badge tone="warning" size="xs">{{ __(':minutes min ago — stale', ['minutes' => $vehicle->ageMinutes()]) }}</x-ui.badge>
                                @else
                                    <span class="block text-xs text-fg-ink-muted">{{ __(':minutes min ago', ['minutes' => $vehicle->ageMinutes()]) }}</span>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td numeric nowrap>
                                <span class="block text-fg-ink">{{ $vehicle->speedLabel() }}</span>

                                @if ($vehicle->trip !== null)
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        wire:click="selectTrip('{{ $vehicle->trip->id }}')"
                                    >
                                        {{ $vehicle->trip->id === $this->trip ? __('Hide track') : __('Track') }}
                                    </x-ui.button>
                                @endif
                            </x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>

        @if ($this->selected !== null || $this->track !== [])
            <x-ui.card as="section" padding="none">
                {{-- A plain header rather than the card's `header` slot: a named slot inside a named
                     slot is where Blade's slot stack loses the table's `head` below. --}}
                <div class="flex flex-wrap items-center gap-2 border-b border-fg-line px-5 py-4">
                    @if ($this->selected?->trip !== null)
                        <a href="{{ route('operations.trip', $this->selected->trip->id) }}" class="font-mono text-sm text-accent">{{ $this->selected->trip->tripNo }}</a>
                        <x-ui.badge :tone="$this->selected->trip->status->tone()">{{ $this->selected->trip->status->label() }}</x-ui.badge>
                        <span class="text-sm text-fg-ink-muted">{{ $this->selected->truckLabel }}</span>
                    @endif

                    <x-ui.text>{{ __(':count fix(es) on file for this trip', ['count' => $this->trackSize]) }}</x-ui.text>

                    <x-ui.button type="button" size="sm" variant="ghost" class="ml-auto" wire:click="clearTrip">{{ __('Close') }}</x-ui.button>
                </div>

                <x-ui.table>
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Fixed at (UTC)') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Latitude') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Longitude') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Speed') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Provider') }}</x-ui.table.th>
                    </x-slot>

                    @forelse (array_reverse($this->track) as $fix)
                        <tr wire:key="fix-{{ $fix->id }}">
                            <x-ui.table.td nowrap>{{ $this->fixAt($fix) }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>{{ number_format((float) $fix->lat_10_7, 5) }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>{{ number_format((float) $fix->lng_10_7, 5) }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>{{ $fix->speed_kph === null ? '—' : $fix->speed_kph.' km/h' }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>{{ $fix->odometer_km === null ? '—' : number_format((int) $fix->odometer_km).' km' }}</x-ui.table.td>
                            <x-ui.table.td nowrap>
                                <span class="text-xs text-fg-ink-muted">{{ $fix->provider }} · {{ $fix->provider_message_id }}</span>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state
                                    icon="signal"
                                    title="{{ __('No fixes on this trip') }}"
                                    description="{{ __('The truck has reported since the trip was closed, or the feed has not reached it yet.') }}"
                                />
                            </td>
                        </tr>
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif
</div>
