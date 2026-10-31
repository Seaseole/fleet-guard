<?php

declare(strict_types=1);

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AddLoad;
use App\Domain\Operations\Actions\AdvanceTrip;
use App\Domain\Operations\Actions\ApprovePod;
use App\Domain\Operations\Actions\CaptureBorderCost;
use App\Domain\Operations\Actions\CloseTrip;
use App\Domain\Operations\Actions\CompleteTrip;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\FinishBorderCosts;
use App\Domain\Operations\Actions\HoldTrip;
use App\Domain\Operations\Actions\IssueTripAccessToken;
use App\Domain\Operations\Actions\MarkCrossingEntered;
use App\Domain\Operations\Actions\MarkCrossingExited;
use App\Domain\Operations\Actions\MarkLoaded;
use App\Domain\Operations\Actions\PlanBorderCrossing;
use App\Domain\Operations\Actions\RecordTripCost;
use App\Domain\Operations\Actions\RejectPod;
use App\Domain\Operations\Actions\RemoveLoad;
use App\Domain\Operations\Actions\ReopenTrip;
use App\Domain\Operations\Actions\RevokeTripAccessToken;
use App\Domain\Operations\Actions\StopTrip;
use App\Domain\Operations\BorderCostKind;
use App\Domain\Operations\BorderCrossingDirection;
use App\Domain\Operations\Data\BorderCostDto;
use App\Domain\Operations\Data\LoadDto;
use App\Domain\Operations\Data\TripCostDto;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\BorderPost;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Load;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\Support\DriverLinkCode;
use App\Domain\Operations\Support\LoadingGate;
use App\Domain\Operations\TripAccessScope;
use App\Domain\Operations\TripCostBucket;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Queries\DriverDirectory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The trip detail (§3.2) — one trip, its schedule, its paper and the state machine it is walking.
 *
 * The dispatch board answers "what leaves on Tuesday"; this screen answers "why is *this* trip stuck",
 * so the §10.2 trail is the spine of it: every move with its reason, its actor and its moment, in the
 * order the engine wrote them (§4.4). A trip that has been held, released and re-dispatched is a
 * different conversation with the customer than one that simply left late, and only the trail says which.
 *
 * Two decisions worth recording. The loading schedule lives here rather than on the board because a load
 * is measured against the truck and trailer named on *this* trip (the weaker of the two limits, §10.2),
 * and `MarkLoaded` refuses until every row has its waybill — so the reason a load cannot be confirmed is
 * printed next to the rows that cause it. And the odometer fields are pre-filled from the truck's
 * projection: `departed_has_odometer` and `odometer_ordered` are engine rules, and a form that starts the
 * driver from a blank box is how they get violated at 06:00.
 *
 * Money and gates follow in their own sections. A trip cost is every seat's to raise only if it holds
 * `expenses.create`, the margin is hidden from the dispatcher who opens this screen most often (§9.3),
 * and a crossing is readable with `crossborder.view` but moved only with `crossborder.manage`.
 */
new #[Title('Trip')] class extends Component
{
    /** Held as a key, not as a model: the Livewire snapshot is client-readable, and a stale instance would render one trip while acting on another. */
    public string $tripId = '';

    public string $loadCommodity = '';

    public string $loadTonnes = '';

    public string $loadCubic = '';

    public string $loadConsignee = '';

    public string $loadOrigin = '';

    public string $loadDestination = '';

    public string $loadStopSequence = '';

    public string $loadWaybill = '';

    public string $departOdometer = '';

    public string $departHours = '';

    public string $endOdometer = '';

    public string $endHours = '';

    /** One field serves hold, cancel, abort and re-open, because §10.2 asks the same question of each. */
    public string $reason = '';

    /**
     * Which door the reason dialog was opened for — `hold`, `stop` or `reopen`.
     *
     * The dialog used to print every reason-shaped action it knew about, so the button that said
     * "Cancel or abort" opened a box offering Hold, Stop and Re-open, and the dispatcher had to work out
     * which one they had already chosen. The name is carried in instead, and the box asks once.
     */
    public string $reasonFor = '';

    /** @var list<string> §9.6's scopes the dispatcher ticks; the Action refuses any this build cannot honour. */
    public array $kioskScopes = [TripAccessScope::Pod->value];

    public string $podRejectReason = '';

    /*
     * The cost form (§4.1 TRIP_COST). Currency is a string and the amount is typed text, parsed
     * exactly once by `Money::ofMajor()` inside `recordCost()` — never by this component's cast.
     */
    public string $costType = TripCostType::Fuel->value;

    public string $costAmount = '';

    public string $costCurrency = '';

    public string $costRate = '1';

    public string $costRateSource = FxRateSource::Manual->value;

    public string $costPaidWith = TripCostPayment::PettyCash->value;

    public string $costOccurredOn = '';

    public string $costDescription = '';

    /* The border section (§4.4, §10.9). One shared charge form, opened for one crossing at a time. */
    public string $crossingPostId = '';

    public string $crossingDirection = BorderCrossingDirection::Entry->value;

    public string $chargeCrossingId = '';

    public string $chargeKind = BorderCostKind::Duty->value;

    public string $chargeAmount = '';

    public string $chargeCurrency = '';

    public string $chargeRate = '1';

    public string $chargeRateSource = FxRateSource::Manual->value;

    public string $chargePaidWith = TripCostPayment::PettyCash->value;

    public string $chargeOccurredOn = '';

    public string $chargeDescription = '';

    public function mount(Trip $trip): void
    {
        $this->tripId = $trip->id;

        $truck = app(FleetDirectory::class)->truck($trip->truck_id);

        // §4.3's projection is the honest starting figure: a departure below it is refused by
        // `trips_odometer_ordered`, so the form suggests the number that will pass.
        $this->departOdometer = (string) ($truck?->odometerKm ?? '');

        $this->costCurrency = CurrentCompany::model()->base_currency_code;
        $this->costOccurredOn = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->chargeCurrency = $this->costCurrency;
        $this->chargeOccurredOn = $this->costOccurredOn;
    }

    public function addLoad(AddLoad $add): void
    {
        $this->validate([
            'loadCommodity' => ['required', 'string', 'max:120'],
            'loadTonnes' => ['required', 'numeric', 'gt:0', 'lt:100000'],
            'loadCubic' => ['nullable', 'numeric', 'gte:0'],
        ]);

        try {
            $add($this->trip(), new LoadDto(
                commodity: $this->loadCommodity,
                tonnes: $this->loadTonnes,
                consignee: $this->loadConsignee === '' ? null : $this->loadConsignee,
                cubicMeters: $this->loadCubic === '' ? null : $this->loadCubic,
                origin: $this->loadOrigin === '' ? null : $this->loadOrigin,
                destination: $this->loadDestination === '' ? null : $this->loadDestination,
                stopSequence: $this->loadStopSequence === '' ? null : $this->loadStopSequence,
                waybillDocumentId: $this->loadWaybill === '' ? null : $this->loadWaybill,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('load', $refused->getMessage());

            return;
        }

        $this->reset(['loadCommodity', 'loadTonnes', 'loadCubic', 'loadConsignee', 'loadOrigin', 'loadDestination', 'loadStopSequence', 'loadWaybill']);
        unset($this->trip, $this->loads, $this->loading);
        $this->dispatch('toast', message: __('Load added.'), kind: 'success');
    }

    public function removeLoad(int $loadId, RemoveLoad $remove): void
    {
        /** @var Load|null $load */
        $load = Load::query()->where('trip_id', $this->tripId)->find($loadId);

        if ($load === null) {
            $this->addError('load', __('That load is no longer on this trip.'));

            return;
        }

        try {
            $remove($load, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('load', $refused->getMessage());

            return;
        }

        unset($this->trip, $this->loads, $this->loading);
        $this->dispatch('toast', message: __('Load removed.'), kind: 'warning');
    }

    public function markLoaded(MarkLoaded $mark): void
    {
        $this->run(fn () => $mark($this->trip(), (int) auth()->id()), 'load');
    }

    public function depart(DepartTrip $depart): void
    {
        $this->validate([
            'departOdometer' => ['required', 'integer', 'gte:0'],
            'departHours' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $this->run(
            fn () => $depart(
                $this->trip(),
                (int) $this->departOdometer,
                $this->departHours === '' ? null : (float) $this->departHours,
                (int) auth()->id(),
            ),
            'depart',
        );
    }

    /**
     * The six fact-free edges of §10.2, through one method.
     *
     * A method per edge would be six places that have to remember the machine only allows these six; the
     * Action already refuses anything else and names the door it belongs to.
     */
    public function advance(string $to, AdvanceTrip $advance): void
    {
        $target = TripStatus::tryFrom($to);

        if ($target === null) {
            $this->addError('advance', __('That is not a state of the trip machine.'));

            return;
        }

        $this->run(fn () => $advance($this->trip(), $target, (int) auth()->id()), 'advance');
    }

    public function complete(CompleteTrip $complete): void
    {
        $this->validate([
            'endOdometer' => ['required', 'integer', 'gte:0'],
            'endHours' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $this->run(
            fn () => $complete(
                $this->trip(),
                (int) $this->endOdometer,
                $this->endHours === '' ? null : (float) $this->endHours,
                (int) auth()->id(),
            ),
            'complete',
        );
    }

    public function closeTrip(CloseTrip $close): void
    {
        $this->run(fn () => $close($this->trip(), (int) auth()->id()), 'close');
    }

    public function reopen(ReopenTrip $reopen): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->run(fn () => $reopen($this->trip(), $this->reason, (int) auth()->id()), 'reason');
    }

    public function stop(StopTrip $stop): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->run(fn () => $stop($this->trip(), $this->reason, (int) auth()->id()), 'reason');
    }

    public function hold(HoldTrip $hold): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->run(fn () => $hold($this->trip(), $this->reason, (int) auth()->id()), 'reason');
    }

    /** Opens the reason box already knowing which door it is standing in front of. */
    public function openReason(string $for): void
    {
        if (! in_array($for, ['hold', 'stop', 'reopen'], true)) {
            return;
        }

        $this->reasonFor = $for;
        $this->resetErrorBag('reason');
        $this->dispatch('open-trip-reason');
    }

    public function reasonTitle(): string
    {
        return match ($this->reasonFor) {
            'hold' => __('Put this trip on hold'),
            'stop' => $this->stopLabel(),
            'reopen' => __('Re-open this trip'),
            default => __('Say why'),
        };
    }

    public function reasonHelper(): string
    {
        return match ($this->reasonFor) {
            'hold' => __('A hold is a blocker, not a cancellation: the truck keeps its plan and the board keeps showing it. The reason stays on the trip’s trail.'),
            'stop' => $this->trip()->status->isPreDeparture()
                ? __('Cancelling before departure frees the truck and the driver, and no cost is claimed. This cannot be undone — the job needs a new trip.')
                : __('Aborting a trip that has already left ends it where it is. The truck comes back to the fleet and the diesel already bought stays on this trip’s costs.'),
            'reopen' => __('A re-open takes a closed trip back to the ledger’s reach. It is an Owner decision, and the facts on it get re-checked before it closes again.'),
            default => __('The reason is kept on the trail.'),
        };
    }

    /** Which action the named reason door runs, so the dialog carries one button rather than three. */
    public function reasonAction(): string
    {
        return match ($this->reasonFor) {
            'hold' => 'hold',
            'stop' => 'stop',
            'reopen' => 'reopen',
            default => '',
        };
    }

    /**
     * §9.6's link, issued once and printed once.
     *
     * The secret and PIN are put in the **session**, not in a public property: a Livewire property travels
     * back to the browser in the snapshot, and that snapshot is exactly the thing the driver's URL must
     * never appear in. The session lives server-side, the reveal is dismissed explicitly, and the row itself
     * only ever holds hashes.
     */
    public function issueDriverLink(IssueTripAccessToken $issue): void
    {
        $this->validate([
            'kioskScopes' => ['required', 'array', 'min:1'],
            'kioskScopes.*' => ['string'],
        ]);

        $trip = $this->trip();

        if ($trip->driver_id === null) {
            $this->addError('kiosk', __('Name the driver on the trip before handing out a link.'));

            return;
        }

        try {
            $issued = $issue($trip, (int) $trip->driver_id, $this->kioskScopes, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('kiosk', $refused->getMessage());

            return;
        }

        session()->put('kiosk.reveal.'.$this->tripId, [
            'url' => $issued['url'],
            'pin' => $issued['pin'],
            // Formatted here rather than stored as a Carbon: the session round-trips whatever it is given,
            // and a template should not have to guess which shape came back.
            'expires_at' => $issued['token']->expires_at?->timezone(CurrentCompany::timezone())->format('d M Y H:i'),
        ]);

        unset($this->accessTokens);
        $this->dispatch('toast', message: __('Driver link issued — copy it now, the secret is not shown again.'), kind: 'success');
    }

    public function dismissDriverReveal(): void
    {
        session()->forget('kiosk.reveal.'.$this->tripId);
    }

    public function revokeDriverLink(string $tokenId, RevokeTripAccessToken $revoke): void
    {
        $token = TripAccessToken::query()->where('trip_id', $this->tripId)->find($tokenId);

        if ($token === null) {
            $this->addError('kiosk', __('That link is not on this trip.'));

            return;
        }

        try {
            $revoke($token);
        } catch (Throwable $refused) {
            $this->addError('kiosk', $refused->getMessage());

            return;
        }

        session()->forget('kiosk.reveal.'.$this->tripId);
        unset($this->accessTokens);
        $this->dispatch('toast', message: __('Driver link closed. The row stays as evidence.'), kind: 'warning');
    }

    /** §10.2's `pod_pending → delivered`, made on the evidence the driver sent. */
    public function approvePod(string $podId, ApprovePod $approve): void
    {
        $pod = ProofOfDelivery::query()->where('trip_id', $this->tripId)->find($podId);

        if ($pod === null) {
            $this->addError('pod', __('That proof is not on this trip.'));

            return;
        }

        try {
            $approve($pod, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('pod', $refused->getMessage());

            return;
        }

        unset($this->trip, $this->proofs, $this->trail);
        $this->dispatch('toast', message: __('Delivery accepted — the trip is delivered.'), kind: 'success');
    }

    public function rejectPod(string $podId, RejectPod $reject): void
    {
        $this->validate(['podRejectReason' => ['required', 'string', 'max:255']]);

        $pod = ProofOfDelivery::query()->where('trip_id', $this->tripId)->find($podId);

        if ($pod === null) {
            $this->addError('pod', __('That proof is not on this trip.'));

            return;
        }

        try {
            $reject($pod, $this->podRejectReason);
        } catch (Throwable $refused) {
            $this->addError('pod', $refused->getMessage());

            return;
        }

        $this->reset('podRejectReason');
        unset($this->proofs);
        $this->dispatch('toast', message: __('Proof sent back — the trip stays where it is.'), kind: 'warning');
    }

    /**
     * Record a direct cost (§4.1 TRIP_COST). The Action posts the journal entry and moves the §6.6
     * projection in the same transaction, so the only thing this method does differently from every
     * other door is parse the typed amount — exactly once, through `Money::ofMajor()`.
     */
    public function recordCost(RecordTripCost $record): void
    {
        $this->validate([
            'costType' => ['required', Rule::in(TripCostType::values())],
            'costAmount' => ['required', 'string'],
            'costCurrency' => ['required', 'string', Rule::exists('currencies', 'code')],
            'costRate' => ['nullable', 'numeric', 'gt:0'],
            'costRateSource' => ['required', Rule::in(array_map(static fn (FxRateSource $source): string => $source->value, FxRateSource::cases()))],
            'costPaidWith' => ['required', Rule::in(array_map(static fn (array $option): string => $option['value'], TripCostPayment::manualOptions()))],
            'costOccurredOn' => ['required', 'date'],
            'costDescription' => ['nullable', 'string', 'max:160'],
        ]);

        $base = CurrentCompany::model()->base_currency_code;
        $currency = Currency::normalise($this->costCurrency);
        $occurredOn = Carbon::parse($this->costOccurredOn);

        try {
            $record($this->trip(), new TripCostDto(
                type: TripCostType::from($this->costType),
                amount: Money::ofMajor($this->costAmount, $currency),
                rate: $currency === $base ? '1' : (string) $this->costRate,
                rateSource: FxRateSource::from($this->costRateSource),
                rateDate: $occurredOn,
                occurredOn: $occurredOn,
                paidWith: TripCostPayment::from($this->costPaidWith),
                description: $this->costDescription === '' ? null : $this->costDescription,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('cost', $refused->getMessage());

            return;
        }

        $this->reset(['costAmount', 'costDescription', 'costType', 'costRate', 'costRateSource', 'costPaidWith']);
        $this->costType = TripCostType::Fuel->value;
        $this->costRate = '1';
        $this->costRateSource = FxRateSource::Manual->value;
        $this->costPaidWith = TripCostPayment::PettyCash->value;
        unset($this->trip, $this->tripCosts, $this->chargeables);
        $this->dispatch('toast', message: __('Cost recorded and posted.'), kind: 'success');
    }

    /* ----------------------------------------------------------- border gates */

    /** §9.3: the Dispatcher reads crossings; only `crossborder.manage` seats move or charge them. */
    public function canSeeCrossings(): bool
    {
        return Gate::allows('crossborder.view') || $this->canManageCrossings();
    }

    public function canManageCrossings(): bool
    {
        return Gate::allows('crossborder.manage');
    }

    /** @return list<BorderCrossing> */
    #[Computed]
    public function crossings(): array
    {
        return BorderCrossing::query()
            ->where('trip_id', $this->tripId)
            ->with(['post', 'costs'])
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function borderPostChoices(): array
    {
        return BorderPost::query()
            ->orderBy('country_code')
            ->orderBy('name')
            ->get()
            ->map(static fn (BorderPost $post): array => ['id' => $post->id, 'label' => $post->label()])
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    public function directionOptions(): array
    {
        return array_map(
            static fn (BorderCrossingDirection $direction): array => [
                'value' => $direction->value,
                'label' => ucfirst($direction->label()),
            ],
            BorderCrossingDirection::cases(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    public function borderKindOptions(): array
    {
        return array_map(
            static fn (BorderCostKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            BorderCostKind::cases(),
        );
    }

    /** The charges a crossing has posted so far, in base minor units — read from the rows, never summed twice. */
    public function crossingCharges(BorderCrossing $crossing): int
    {
        return (int) $crossing->costs->sum('base_amount');
    }

    public function planCrossing(PlanBorderCrossing $plan): void
    {
        $this->validate([
            'crossingPostId' => ['required', 'integer'],
            'crossingDirection' => ['required', Rule::in(BorderCrossingDirection::values())],
        ]);

        $post = BorderPost::query()->find($this->crossingPostId);

        if ($post === null) {
            $this->addError('crossing', __('That gate is not in the register. Add it on the cross-border screen first.'));

            return;
        }

        try {
            $crossing = $plan($this->trip(), $post, BorderCrossingDirection::from($this->crossingDirection), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('crossing', $refused->getMessage());

            return;
        }

        $this->crossingPostId = '';
        unset($this->crossings);
        $this->dispatch('toast', message: __('Crossing planned at :gate.', ['gate' => $crossing->label()]), kind: 'success');
    }

    public function enterCrossing(string $crossingId, MarkCrossingEntered $enter): void
    {
        $crossing = $this->crossing($crossingId);

        if ($crossing === null) {
            return;
        }

        try {
            $enter($crossing, actorId: (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('crossing', $refused->getMessage());

            return;
        }

        unset($this->crossings);
        $this->dispatch('toast', message: __('The truck is at :gate.', ['gate' => $crossing->label()]), kind: 'success');
    }

    public function exitCrossing(string $crossingId, MarkCrossingExited $exit): void
    {
        $crossing = $this->crossing($crossingId);

        if ($crossing === null) {
            return;
        }

        try {
            $exit($crossing, actorId: (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('crossing', $refused->getMessage());

            return;
        }

        unset($this->crossings);
        $this->dispatch('toast', message: __('Through :gate — capture the charges now.', ['gate' => $crossing->label()]), kind: 'success');
    }

    public function finishCrossing(string $crossingId, FinishBorderCosts $finish): void
    {
        $crossing = $this->crossing($crossingId);

        if ($crossing === null) {
            return;
        }

        try {
            $finish($crossing, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('crossing', $refused->getMessage());

            return;
        }

        unset($this->crossings);
        $this->dispatch('toast', message: __(':gate is finished — the costs are in.', ['gate' => $crossing->label()]), kind: 'success');
    }

    public function openCharge(string $crossingId): void
    {
        $crossing = $this->crossing($crossingId);

        if ($crossing === null) {
            return;
        }

        $this->reset(['chargeKind', 'chargeAmount', 'chargeRate', 'chargeDescription']);
        $this->chargeCrossingId = $crossing->id;
        $this->chargeKind = BorderCostKind::Duty->value;
        $this->chargeRate = '1';
        $this->chargeRateSource = FxRateSource::Manual->value;
        $this->chargePaidWith = TripCostPayment::PettyCash->value;
        $this->resetValidation();

        $this->dispatch('open-border-charge');
    }

    /**
     * Capture one charge at the gate (§4.4). The form parses the amount exactly once and hands the
     * Action a DTO; the Action creates the border cost, posts its trip cost and refreshes the trip's
     * rollup in one transaction, so this method has nothing to reconcile afterwards.
     */
    public function captureCharge(CaptureBorderCost $capture): void
    {
        $crossing = $this->crossing($this->chargeCrossingId);

        if ($crossing === null) {
            return;
        }

        $this->validate([
            'chargeKind' => ['required', Rule::in(BorderCostKind::values())],
            'chargeAmount' => ['required', 'string'],
            'chargeCurrency' => ['required', 'string', Rule::exists('currencies', 'code')],
            'chargeRate' => ['nullable', 'numeric', 'gt:0'],
            'chargeRateSource' => ['required', Rule::in(array_map(static fn (FxRateSource $source): string => $source->value, FxRateSource::cases()))],
            'chargePaidWith' => ['required', Rule::in(array_map(static fn (array $option): string => $option['value'], TripCostPayment::manualOptions()))],
            'chargeOccurredOn' => ['required', 'date'],
            'chargeDescription' => ['nullable', 'string', 'max:160'],
        ]);

        $base = CurrentCompany::model()->base_currency_code;
        $currency = Currency::normalise($this->chargeCurrency);
        $occurredOn = Carbon::parse($this->chargeOccurredOn);

        try {
            $capture($crossing, new BorderCostDto(
                kind: BorderCostKind::from($this->chargeKind),
                amount: Money::ofMajor($this->chargeAmount, $currency),
                rate: $currency === $base ? '1' : (string) $this->chargeRate,
                rateSource: FxRateSource::from($this->chargeRateSource),
                rateDate: $occurredOn,
                occurredOn: $occurredOn,
                paidWith: TripCostPayment::from($this->chargePaidWith),
                description: $this->chargeDescription === '' ? null : $this->chargeDescription,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('charge', $refused->getMessage());

            return;
        }

        $this->reset(['chargeCrossingId', 'chargeAmount', 'chargeDescription', 'chargeKind', 'chargeRate', 'chargeRateSource', 'chargePaidWith']);
        $this->chargeKind = BorderCostKind::Duty->value;
        $this->chargeRate = '1';
        $this->chargeRateSource = FxRateSource::Manual->value;
        $this->chargePaidWith = TripCostPayment::PettyCash->value;
        unset($this->crossings, $this->trip, $this->tripCosts, $this->chargeables);
        $this->dispatch('close-border-charge');
        $this->dispatch('toast', message: __('Charge captured and posted.'), kind: 'success');
    }

    /** A crossing is only ever acted on through this trip's own rows. */
    private function crossing(string $crossingId): ?BorderCrossing
    {
        $crossing = BorderCrossing::query()->where('trip_id', $this->tripId)->find($crossingId);

        if ($crossing === null) {
            $this->addError('crossing', __('That crossing is not on this trip.'));
        }

        return $crossing;
    }

    /**
     * One shape for every transition: call the Action, report its refusal beside the button, and refresh
     * the reads that the move changed.
     *
     * @param  callable(): Trip  $move
     */
    private function run(callable $move, string $bag): void
    {
        try {
            $move();
        } catch (Throwable $refused) {
            $this->addError($bag, $refused->getMessage());

            return;
        }

        unset($this->trip, $this->loads, $this->loading, $this->trail);
        $this->reset('reason');
        $this->dispatch('toast', message: __('Trip updated.'), kind: 'success');
    }

    #[Computed]
    public function trip(): Trip
    {
        return Trip::query()->findOrFail($this->tripId);
    }

    #[Computed]
    public function loads()
    {
        return $this->trip()->loads;
    }

    /** §10.2's `assigned → loaded` answer, printed as the reason rather than as a greyed button. */
    #[Computed]
    public function loading(): array
    {
        return app(LoadingGate::class)->findings($this->trip());
    }

    /** @return list<\App\Domain\Kernel\Audit\Models\OperationalEvent> */
    #[Computed]
    public function trail(): array
    {
        return \App\Domain\Kernel\Audit\Models\OperationalEvent::query()
            ->where('aggregate_type', 'trip')
            ->where('aggregate_id', $this->tripId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** The paper a load may be filed against: current delivery notes, not voided, not superseded. */
    #[Computed]
    public function waybills()
    {
        return Document::query()
            ->where('lifecycle', Lifecycle::Current->value)
            ->whereIn('type', [DocumentType::DeliveryNote->value, DocumentType::Pod->value, DocumentType::Weighbridge->value])
            ->orderBy('title')
            ->get(['id', 'title', 'type', 'expires_on']);
    }

    /** @return list<TripAccessToken> */
    #[Computed]
    public function accessTokens(): array
    {
        return TripAccessToken::query()
            ->where('trip_id', $this->tripId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /** @return list<ProofOfDelivery> */
    #[Computed]
    public function proofs(): array
    {
        return ProofOfDelivery::query()
            ->where('trip_id', $this->tripId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /** @return list<TripCost> */
    #[Computed]
    public function tripCosts(): array
    {
        return $this->trip()->tripCosts()->get()->all();
    }

    /** @return list<Chargeable> */
    #[Computed]
    public function chargeables(): array
    {
        return $this->trip()->chargeables()->get()->all();
    }

    /** The money section exists for three different seats, and each half has its own ability. */
    public function canSeeMoney(): bool
    {
        return $this->canRecordCosts() || $this->canSeeMargin() || $this->canSeeBilling();
    }

    public function canRecordCosts(): bool
    {
        return Gate::allows('expenses.create');
    }

    public function canSeeMargin(): bool
    {
        return Gate::allows('trips.margin.view');
    }

    public function canSeeBilling(): bool
    {
        return Gate::allows('invoices.view');
    }

    /** §10.2: a closed trip, or one that never left, has costs that are final rather than editable. */
    public function costsAreFrozen(): bool
    {
        $trip = $this->trip();

        return $trip->costs_frozen || in_array($trip->status, [TripStatus::Cancelled, TripStatus::Aborted], true);
    }

    /** A fuel issue posts a trip cost and moves the rollup; this section's cached reads are now stale. */
    #[On('trip-fuel-recorded')]
    public function refreshAfterFuel(): void
    {
        unset($this->trip, $this->tripCosts, $this->chargeables);
    }

    public function canAddCosts(): bool
    {
        return $this->canRecordCosts() && ! $this->costsAreFrozen();
    }

    /** Money printed with its own currency, and with the code spelled out when it is not the base one. */
    public function money(int $minorUnits, string $currency): string
    {
        return Money::ofMinor($minorUnits, $currency)->format(CurrentCompany::model()->base_currency_code);
    }

    /** @return list<array{value: string, label: string}> */
    public function costTypeOptions(): array
    {
        return TripCostType::options();
    }

    /** @return list<array{value: string, label: string}> */
    public function paymentOptions(): array
    {
        return TripCostPayment::manualOptions();
    }

    /** @return list<array{value: string, label: string}> */
    public function currencyOptions(): array
    {
        return array_values(array_map(
            static fn (Currency $currency): array => ['value' => $currency->code, 'label' => $currency->code.' · '.$currency->name],
            Currency::active(),
        ));
    }

    /**
     * The rate sources a person may claim, and only those. `opening_balance` and `manual_override`
     * are written by other doors (§5.2 edit rules) and are not a choice on this form.
     *
     * @return list<array{value: string, label: string}>
     */
    public function rateSourceOptions(): array
    {
        $labels = [
            FxRateSource::Manual->value => __('A rate I typed'),
            FxRateSource::RbgDaily->value => __('RBG daily rate'),
            FxRateSource::Bpb->value => __('BPB published rate'),
            FxRateSource::BankStatement->value => __('What the bank charged'),
            FxRateSource::Contract->value => __('A contracted rate'),
        ];

        return array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }

    public function isForeignCost(): bool
    {
        return Currency::normalise($this->costCurrency) !== CurrentCompany::model()->base_currency_code;
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    /** @return list<array{label: string, amount: int}> */
    public function bucketRows(): array
    {
        $trip = $this->trip();

        return array_map(
            static fn (TripCostBucket $bucket): array => [
                'label' => $bucket->label(),
                'amount' => (int) $trip->getAttribute($bucket->column()),
            ],
            TripCostBucket::cases(),
        );
    }

    /** The one-time reveal, read back from the session (§9.6). */
    public function driverReveal(): ?array
    {
        $reveal = session()->get('kiosk.reveal.'.$this->tripId);

        return is_array($reveal) ? $reveal : null;
    }

    /**
     * The reveal's link, drawn (§9.6).
     *
     * Read off the session here rather than kept in a property: a Livewire property rides back to the
     * browser in every snapshot, and the secret belongs in the page the dispatcher is looking at, not in
     * the request that every subsequent keystroke sends.
     */
    public function driverQrSvg(): ?string
    {
        $reveal = $this->driverReveal();

        return $reveal === null ? null : DriverLinkCode::svg((string) $reveal['url']);
    }

    /**
     * The hand-off §9.6 assumes: an office with the link, a driver at a gate with a phone.
     *
     * WhatsApp opens with the message already typed so neither the 48-character secret nor the PIN is
     * re-keyed on a small screen. The PIN goes in the text and deliberately not in the QR: the page asks
     * for it on every submission, and a code that carried it would be a photograph anyone could submit
     * with.
     */
    public function driverShareUrl(): ?string
    {
        $reveal = $this->driverReveal();

        if ($reveal === null) {
            return null;
        }

        $body = implode("\n", array_filter([
            __('Fleet Guard :no', ['no' => $this->trip()->trip_no]),
            $reveal['url'],
            __('PIN :pin — the page asks for it on every submission.', ['pin' => $reveal['pin']]),
            __('Opens until :at', ['at' => $reveal['expires_at'] ?? __('—')]),
        ], static fn (string $line): bool => $line !== ''));

        return 'https://wa.me/?text='.rawurlencode($body);
    }

    /** @return list<array{value: string, label: string}> */
    public function scopeOptions(): array
    {
        return array_map(
            static fn (TripAccessScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            TripAccessScope::implemented(),
        );
    }

    public function canIssueDriverLink(): bool
    {
        return Gate::allows('kiosk.token.issue');
    }

    public function canApprovePod(): bool
    {
        return Gate::allows('pod.approve');
    }

    /** §10.2: only a submitted proof has a decision left to make, and only an office seat may make it. */
    public function podAwaitingDecision(ProofOfDelivery $pod): bool
    {
        return $pod->status === PodStatus::Submitted && $this->canApprovePod();
    }

    public function tokenState(TripAccessToken $token): string
    {
        return match ($token->closedBecause()) {
            'revoked' => __('Revoked'),
            'expired' => __('Expired'),
            default => __('Live'),
        };
    }

    public function tokenTone(TripAccessToken $token): string
    {
        return match ($token->closedBecause()) {
            'revoked' => 'danger',
            'expired' => 'warning',
            default => 'success',
        };
    }

    public function tokenScopes(TripAccessToken $token): string
    {
        return implode(', ', array_map(static fn (TripAccessScope $scope): string => $scope->label(), $token->scopeList()));
    }

    public function truckLabel(): string
    {
        $trip = $this->trip();

        return $trip->truck_id === null
            ? __('(no truck named)')
            : (app(FleetDirectory::class)->truck($trip->truck_id)?->label ?? '#'.$trip->truck_id);
    }

    public function trailerLabel(): string
    {
        $trip = $this->trip();

        if ($trip->trailer_id === null) {
            return __('truck only');
        }

        $trailer = app(FleetDirectory::class)->trailer($trip->trailer_id);

        if ($trailer === null) {
            return '#'.$trip->trailer_id;
        }

        // §10.2 measures the load against the weaker of the two, so the board says which one binds.
        return $trailer->label.($trailer->capacityTonnes === null ? '' : ' · '.$trailer->capacityTonnes.' t');
    }

    public function driverLabel(): string
    {
        $trip = $this->trip();

        return $trip->driver_id === null
            ? __('(no driver named)')
            : (app(DriverDirectory::class)->find($trip->driver_id)?->name ?? '#'.$trip->driver_id);
    }

    /** The edges this trip may take from here through the fact-free door (§10.2). */
    public function nextEdges(): array
    {
        $from = $this->trip()->status;

        $allowed = match ($from) {
            TripStatus::Departed => [TripStatus::InTransit],
            TripStatus::InTransit => [TripStatus::AtBorder, TripStatus::Arrived],
            TripStatus::AtBorder => [TripStatus::InTransit],
            TripStatus::Arrived => [TripStatus::PodPending],
            TripStatus::PodPending => [TripStatus::Delivered],
            default => [],
        };

        return array_map(
            static fn (TripStatus $to): array => ['value' => $to->value, 'label' => $to->label()],
            $allowed,
        );
    }

    /*
     * The doors offered, each paired with the permission that opens it.
     *
     * Blade cannot see this class's `use` lines, so a status comparison written in the template would have
     * to name `TripStatus::` by its full namespace and repeat the policy call inline — three places to
     * disagree with §10.2 instead of one.
     */

    public function canDispatchElsewhere(): bool
    {
        return $this->trip()->status === TripStatus::Planned && Gate::allows('dispatch', $this->trip());
    }

    public function canDepart(): bool
    {
        return in_array($this->trip()->status, [TripStatus::Assigned, TripStatus::Loaded], true)
            && Gate::allows('dispatch', $this->trip());
    }

    public function canMarkLoaded(): bool
    {
        return $this->trip()->status === TripStatus::Assigned
            && $this->loading() === []
            && Gate::allows('dispatch', $this->trip());
    }

    public function loadingIsRefused(): bool
    {
        return $this->trip()->status === TripStatus::Assigned && $this->loading() !== [];
    }

    public function canAdvance(): bool
    {
        return $this->nextEdges() !== [] && Gate::allows('advance', $this->trip());
    }

    /**
     * The hold door, read from the trip's own transition table rather than from "is it before departure".
     *
     * §10.2 lets `planned` and `assigned` go on hold and stops there — a loaded trip has committed its
     * manifest, and `loaded` has only one edge out. Asking the table instead of the prose keeps the button
     * and the machine agreeing, which they did not: the old test offered hold on a loaded trip and the
     * Action refused the click.
     */
    public function canHold(): bool
    {
        $trip = $this->trip();

        return $trip->canTransitionTo(TripStatus::OnHold) && Gate::allows('hold', $trip);
    }

    public function canStop(): bool
    {
        $trip = $this->trip();

        // Two different doors, one control: before the truck leaves this is a cancellation, after it is an
        // abort — and the abort is a decision about money, so §9.3 gives it to a different seat.
        return ($trip->canTransitionTo(TripStatus::Cancelled) && Gate::allows('cancel', $trip))
            || ($trip->canTransitionTo(TripStatus::Aborted) && Gate::allows('abort', $trip));
    }

    /** What stopping this trip actually does, in the words §10.2 uses for the edge. */
    public function stopLabel(): string
    {
        return $this->trip()->status->isPreDeparture() ? __('Cancel this trip') : __('Abort this trip');
    }

    /**
     * Why the stop door is not offered, when a dispatcher would expect one.
     *
     * A trip that has been loaded cannot be unwound from this screen and an aborted trip belongs to the
     * seat holding `trips.complete`. Either way the old markup simply had no button, which reads as a
     * screen that has forgotten how to cancel — so the reason is printed instead of the silence.
     */
    public function stopIsRefused(): bool
    {
        $trip = $this->trip();

        if ($trip->status->isTerminal() || $trip->status === TripStatus::Closed) {
            return false;
        }

        return ! $this->canStop() && ($trip->canTransitionTo(TripStatus::Cancelled) || $trip->canTransitionTo(TripStatus::Aborted));
    }

    public function canComplete(): bool
    {
        $trip = $this->trip();

        // `CompleteTrip`'s two doors, both of them: the normal one from `delivered`, and a re-run after an
        // Owner re-opened. `pod_pending` was offered here but never accepted there, so the button on a proof
        // still waiting for approval was a guaranteed refusal — it is explained instead of offered.
        return in_array($trip->status, [TripStatus::Delivered, TripStatus::Reopened], true)
            && Gate::allows('complete', $trip);
    }

    /** The trip is waiting on a signature, not on the desk: the completion door has a reason. */
    public function completionIsRefused(): bool
    {
        return $this->trip()->status === TripStatus::PodPending && Gate::allows('complete', $this->trip());
    }

    public function canClose(): bool
    {
        return $this->trip()->status === TripStatus::Completed && Gate::allows('close', $this->trip());
    }

    public function canReopen(): bool
    {
        return $this->trip()->status === TripStatus::Closed && Gate::allows('reopen', $this->trip());
    }

    public function canEditLoads(): bool
    {
        return $this->trip()->status->isPreDeparture() && Gate::allows('update', $this->trip());
    }

    /** A moment in the company's clock (§2), or the em dash the register uses for "has not happened". */
    public function at(?CarbonInterface $moment): string
    {
        return $moment === null ? '—' : Carbon::instance($moment)->timezone(CurrentCompany::timezone())->format('d M Y H:i');
    }
}; ?>

<div class="grid gap-6">
    @php($trip = $this->trip)

    <header class="grid gap-2">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.heading size="xl" level="1">{{ $trip->trip_no }}</x-ui.heading>
            <x-ui.badge :tone="$trip->status->tone()">{{ $trip->status->label() }}</x-ui.badge>
            <a href="{{ route('operations.dispatch') }}" class="text-sm text-accent">{{ __('Back to the board') }}</a>
        </div>

        <p class="text-sm text-fg-ink-secondary">
            {{ $trip->job?->job_no ?? __('No job') }}
            · {{ $trip->route?->code ?? __('no corridor') }}
            · {{ $trip->tonnageLabel() }}
            @if ($trip->distanceCoveredKm() !== null)
                · {{ __(':km km covered', ['km' => rtrim(rtrim(number_format($trip->distanceCoveredKm(), 1, '.', ' '), '0'), '.')]) }}
            @endif
        </p>
    </header>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- The plan: what was committed, and the facts gathered on the road. --}}
        <section class="grid gap-4 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <x-ui.subheading>{{ __('Plan') }}</x-ui.subheading>

            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Truck') }}</dt>
                    <dd class="text-fg-ink">{{ $this->truckLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Trailer') }}</dt>
                    <dd class="text-fg-ink">{{ $this->trailerLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Driver') }}</dt>
                    <dd class="text-fg-ink">{{ $this->driverLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Planned departure') }}</dt>
                    <dd class="text-fg-ink">{{ $trip->planned_departure_at?->timezone(App\Domain\Kernel\Tenancy\CurrentCompany::timezone())->format('d M Y H:i') ?? __('unscheduled') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Departed') }}</dt>
                    <dd class="text-fg-ink">
                        {{ $trip->departed_at?->format('d M Y H:i') ?? __('—') }}
                        @if ($trip->odometer_start_km !== null)
                            <span class="text-fg-ink-muted">· {{ number_format($trip->odometer_start_km) }} km</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-ink-muted">{{ __('Completed') }}</dt>
                    <dd class="text-fg-ink">
                        {{ $trip->completed_at?->format('d M Y H:i') ?? __('—') }}
                        @if ($trip->odometer_end_km !== null)
                            <span class="text-fg-ink-muted">· {{ number_format($trip->odometer_end_km) }} km</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        {{-- The state machine's own record, in the order it was written (§4.4). --}}
        <section class="grid gap-4 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <x-ui.subheading>{{ __('Trail') }}</x-ui.subheading>

            @if ($this->trail === [])
                <x-ui.text>{{ __('Nothing has been recorded against this trip yet.') }}</x-ui.text>
            @else
                <ol class="grid gap-3">
                    @foreach ($this->trail as $event)
                        <li class="grid gap-0.5 border-l-2 border-fg-line pl-3">
                            <span class="text-sm text-fg-ink">
                                {{ $event->from_state === null ? __('Opened') : __('{from} →', ['from' => $event->from_state]) }}
                                <span class="font-medium">{{ $event->to_state }}</span>
                            </span>
                            <span class="text-xs text-fg-ink-muted">
                                {{ $event->occurred_at->timezone(App\Domain\Kernel\Tenancy\CurrentCompany::timezone())->format('d M Y H:i') }}
                                · {{ $event->actor_kind }}
                            </span>
                            @if ($event->reason !== null)
                                <span class="text-xs text-fg-ink-secondary">{{ $event->reason }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>

    {{-- The loading schedule: §10.2's gate measured against the truck AND the trailer. --}}
    <section class="grid gap-4 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.subheading>{{ __('Loading schedule') }}</x-ui.subheading>

            @if ($this->canEditLoads())
                <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-trip-load')">{{ __('Add a load') }}</x-ui.button>
            @endif
        </div>

        @if ($this->loads->isEmpty())
            <x-ui.empty-state
                icon="cube"
                title="{{ __('Nothing on it') }}"
                description="{{ __('An empty manifest is refused at loaded — add what the truck is carrying, each line with its waybill.') }}"
            />
        @else
            <x-ui.table :caption="__('What this trip is carrying, one line per stop')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Stop') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Commodity') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Tonnes') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Cubic') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Consignee') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Waybill') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </x-slot>

                @foreach ($this->loads as $load)
                    <tr wire:key="load-{{ $load->id }}">
                        <x-ui.table.td nowrap>{{ $load->stop_sequence ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $load->commodity }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ rtrim(rtrim((string) $load->tonnes_10_2, '0'), '.') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $load->cubic_meters_10_2 === null ? '—' : rtrim(rtrim((string) $load->cubic_meters_10_2, '0'), '.') }}</x-ui.table.td>
                        <x-ui.table.td>{{ $load->consignee ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($load->waybill_document_id === null)
                                <span class="text-danger">{{ __('missing') }}</span>
                            @else
                                <span class="fg-identifier text-xs">{{ $load->waybill_document_id }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric>
                            @if ($this->canEditLoads())
                                <x-ui.button size="xs" variant="ghost" wire:click="removeLoad({{ $load->id }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>

            <p class="text-sm text-fg-ink-secondary">{{ $trip->tonnageLabel() }}</p>
        @endif

        {{-- The gate, printed as the reason instead of as a disabled control. --}}
        @if ($this->canMarkLoaded())
            <div>
                <x-ui.button wire:click="markLoaded" wire:loading.attr="disabled" wire:target="markLoaded">
                    <span wire:loading.remove wire:target="markLoaded">{{ __('Mark this trip loaded') }}</span>
                    <span wire:loading wire:target="markLoaded">{{ __('Closing the manifest…') }}</span>
                </x-ui.button>
            </div>
        @elseif ($this->loadingIsRefused())
            <x-ui.alert tone="warning" :title="__('Not yet loadable')">
                <ul class="grid gap-1 text-sm">
                    @foreach ($this->loading as $finding)
                        <li>· {{ $finding }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @error('load')
            <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
        @enderror
    </section>

    {{-- The doors this trip may still walk, and the facts each one asks for. --}}
    <section class="grid gap-5 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
        <x-ui.subheading>{{ __('Move it on') }}</x-ui.subheading>

        @if ($this->canDispatchElsewhere())
            <p class="text-sm text-fg-ink-secondary">
                {{ __('Dispatch happens on the board, where the other trucks and the week are visible (§10.2).') }}
                <a href="{{ route('operations.dispatch') }}" class="text-accent">{{ __('Open the board') }}</a>
            </p>
        @endif

        @if ($this->canDepart())
            {{-- ui-scope: departure (canDepart and canComplete are disjoint §10.2 states, so these two forms never share a screen) --}}
            <form class="grid gap-3 sm:grid-cols-2" wire:submit="depart">
                    <x-ui.input name="departOdometer" wire:model="departOdometer" :label="__('Odometer at departure (km)')" type="number" />
                    <x-ui.input name="departHours" wire:model="departHours" :label="__('Engine hours at departure')" type="number" step="0.1" />

                    @error('depart') <div class="sm:col-span-2"><x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert></div> @enderror

                    <div>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="depart">
                            <span wire:loading.remove wire:target="depart">{{ __('Record the departure') }}</span>
                            <span wire:loading wire:target="depart">{{ __('Sending it…') }}</span>
                        </x-ui.button>
                    </div>
                </form>
        @endif

        @if ($this->canAdvance())
            <div class="flex flex-wrap items-center gap-2">
                    @foreach ($this->nextEdges() as $edge)
                        <x-ui.button
                            size="sm"
                            variant="outline"
                            wire:click="advance('{{ $edge['value'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="advance"
                        >
                            {{ __('Mark :status', ['status' => $edge['label']]) }}
                        </x-ui.button>
                    @endforeach

                @error('advance') <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert> @enderror
            </div>
        @endif

        @if ($this->canComplete())
            {{-- ui-scope: completion (the closing reading only appears once the POD is approved, so this form and the departure form are disjoint §10.2 states) --}}
            <form class="grid gap-3 sm:grid-cols-2" wire:submit="complete">
                    <x-ui.input name="endOdometer" wire:model="endOdometer" :label="__('Odometer on arrival (km)')" type="number" />
                    <x-ui.input name="endHours" wire:model="endHours" :label="__('Engine hours at the end')" type="number" step="0.1" />

                    <p class="text-xs text-fg-ink-muted sm:col-span-2">
                        {{ __('Take these from the truck’s own book at the yard. They cannot be guessed: the distance the trip ran is worked out of them, and diesel is judged against it.') }}
                    </p>

                    @error('complete') <div class="sm:col-span-2"><x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert></div> @enderror

                    <div>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="complete">
                            <span wire:loading.remove wire:target="complete">{{ __('Complete the trip') }}</span>
                            <span wire:loading wire:target="complete">{{ __('Completing…') }}</span>
                        </x-ui.button>
                    </div>
                </form>
        @elseif ($this->completionIsRefused())
            {{-- The door the desk wants is real, but it opens on the driver's signature, not on a button here. --}}
            <x-ui.alert tone="warning" :title="__('Not completed yet')">
                <p class="text-sm">
                    {{ __('This trip is waiting on its proof of delivery. Accept the delivery in the evidence panel below and the completion form appears here.') }}
                </p>
            </x-ui.alert>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-t border-fg-line pt-4">
            @if ($this->canHold())
                <x-ui.button size="sm" variant="ghost" wire:click="openReason('hold')">{{ __('Put on hold') }}</x-ui.button>
            @endif

            @if ($this->canStop())
                <x-ui.button size="sm" variant="ghost" wire:click="openReason('stop')">{{ $this->stopLabel() }}</x-ui.button>
            @endif

            @if ($this->canClose())
                <x-ui.button
                    size="sm"
                    variant="ghost"
                    wire:click="closeTrip"
                    wire:confirm="{{ __('Close and freeze this trip? Costs stop being editable, and only an Owner can re-open it.') }}"
                    wire:loading.attr="disabled"
                    wire:target="closeTrip"
                >
                    <span wire:loading.remove wire:target="closeTrip">{{ __('Close and freeze the costs') }}</span>
                    <span wire:loading wire:target="closeTrip">{{ __('Freezing…') }}</span>
                </x-ui.button>
            @endif

            @if ($this->canReopen())
                <x-ui.button size="sm" variant="ghost" wire:click="openReason('reopen')">{{ __('Re-open') }}</x-ui.button>
            @endif

            {{-- Where a door the trip would allow is not this seat's to open: said, not hidden. --}}
            @if ($this->stopIsRefused())
                <p class="text-xs text-fg-ink-muted">
                    {{ __('Only a seat that holds the money decision can end this trip now — it has left, so ending it is an abort, not a cancellation.') }}
                </p>
            @endif

            @error('close') <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert> @enderror
        </div>

        @error('reason') <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert> @enderror
    </section>

    {{--
        §10.9's gates on this trip. Three facts shape the section: the crossing is planned before the
        truck arrives (or backfilled from the paper), the money is captured while it is at the barrier
        or after it left, and finishing is a person saying the gate is done being charged. The section
        is readable by every seat with `crossborder.view`; the buttons ask `crossborder.manage`.
    --}}
    @if ($this->canSeeCrossings())
        <section class="grid gap-5 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-ui.subheading>{{ __('Border gates') }}</x-ui.subheading>
                <a href="{{ route('crossborder.index') }}" class="text-sm text-accent">{{ __('The gate register') }}</a>
            </div>

            @if ($this->crossings === [])
                <x-ui.text>{{ __('No gate is planned on this trip. Plan one before the truck reaches the border, or from the paper when it comes back.') }}</x-ui.text>
            @else
                <x-ui.table :caption="__('The border gates this trip walks, and how far each one has got')">
                    <x-slot name="head">
                        <x-ui.table.th>{{ __('Gate') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Entered') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Exited') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Charges') }}</x-ui.table.th>
                        @if ($this->canManageCrossings())
                            <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                        @endif
                    </x-slot>

                    @foreach ($this->crossings as $crossing)
                        <tr wire:key="crossing-{{ $crossing->id }}">
                            <x-ui.table.td>
                                <span class="block text-fg-ink">{{ $crossing->post?->name ?? __('Gate #:id', ['id' => $crossing->border_post_id]) }}</span>
                                <span class="block text-xs text-fg-ink-muted">{{ $crossing->country_code }} · {{ $crossing->direction->label() }}</span>
                                @if ($crossing->transit_doc_no !== null)
                                    <span class="block text-xs text-fg-ink-muted">{{ __('Transit :no', ['no' => $crossing->transit_doc_no]) }}</span>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td nowrap>{{ $this->at($crossing->entered_at) }}</x-ui.table.td>
                            <x-ui.table.td nowrap>
                                {{ $this->at($crossing->exited_at) }}
                                @if ($crossing->clearance_state !== null)
                                    <span class="block text-xs text-fg-ink-muted">{{ $crossing->clearance_state }}</span>
                                @endif
                            </x-ui.table.td>

                            <x-ui.table.td>
                                <x-ui.badge :tone="$crossing->status->tone()">{{ $crossing->status->label() }}</x-ui.badge>
                            </x-ui.table.td>

                            <x-ui.table.td numeric nowrap>
                                @if ($crossing->costs->isEmpty())
                                    <span class="text-fg-ink-muted">{{ __('none') }}</span>
                                @else
                                    <span class="text-fg-ink">{{ $this->money($this->crossingCharges($crossing), $this->baseCurrency()) }}</span>
                                    <span class="block text-xs text-fg-ink-muted">{{ __(':count charge(s)', ['count' => $crossing->costs->count()]) }}</span>
                                @endif
                            </x-ui.table.td>

                            @if ($this->canManageCrossings())
                                <x-ui.table.td numeric nowrap>
                                    @if ($crossing->status === App\Domain\Operations\BorderCrossingStatus::Planned)
                                        <x-ui.button size="xs" variant="ghost" wire:click="enterCrossing('{{ $crossing->id }}')">{{ __('At the gate') }}</x-ui.button>
                                    @endif

                                    @if ($crossing->status === App\Domain\Operations\BorderCrossingStatus::Entered)
                                        <x-ui.button size="xs" variant="ghost" wire:click="exitCrossing('{{ $crossing->id }}')">{{ __('Through the gate') }}</x-ui.button>
                                        <x-ui.button size="xs" variant="ghost" wire:click="openCharge('{{ $crossing->id }}')">{{ __('Add a charge') }}</x-ui.button>
                                    @endif

                                    @if ($crossing->status === App\Domain\Operations\BorderCrossingStatus::Exited)
                                        <x-ui.button size="xs" variant="ghost" wire:click="openCharge('{{ $crossing->id }}')">{{ __('Add a charge') }}</x-ui.button>
                                        <x-ui.button size="xs" wire:click="finishCrossing('{{ $crossing->id }}')">{{ __('Finish the costs') }}</x-ui.button>
                                    @endif
                                </x-ui.table.td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif

            @error('crossing')
                <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
            @enderror

            @if ($this->canManageCrossings())
                <form wire:submit="planCrossing" class="flex flex-wrap items-end gap-3 border-t border-fg-line pt-4">
                    <div class="grid gap-1">
                        <label for="crossing-post" class="text-sm text-fg-ink">{{ __('Gate') }}</label>
                        <x-ui.select name="crossingPostId" id="crossing-post" wire:model="crossingPostId">
                            <option value="">{{ __('Choose a gate…') }}</option>
                            @foreach ($this->borderPostChoices as $post)
                                <option value="{{ $post['id'] }}">{{ $post['label'] }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1">
                        <label for="crossing-direction" class="text-sm text-fg-ink">{{ __('Direction') }}</label>
                        <x-ui.select name="crossingDirection" id="crossing-direction" wire:model="crossingDirection">
                            @foreach ($this->directionOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Plan the crossing') }}</x-ui.button>

                    @if ($this->borderPostChoices === [])
                        <span class="text-xs text-fg-ink-muted">{{ __('No gate is on file yet — register one from the cross-border screen.') }}</span>
                    @endif
                </form>
            @endif
        </section>

        {{-- One charge at one gate. The amount is typed once and posted through the trip cost door. --}}
        @if ($this->canManageCrossings())
            <x-ui.dialog name="border-charge" :title="__('Capture a border charge')">
                <form wire:submit="captureCharge" class="grid gap-4 p-5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <label for="charge-kind" class="text-sm text-fg-ink">{{ __('What was charged') }}</label>
                            <x-ui.select name="chargeKind" id="charge-kind" wire:model="chargeKind">
                                @foreach ($this->borderKindOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="grid gap-1">
                            <label for="charge-paid-with" class="text-sm text-fg-ink">{{ __('Paid with') }}</label>
                            <x-ui.select name="chargePaidWith" id="charge-paid-with" wire:model="chargePaidWith">
                                @foreach ($this->paymentOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="chargeAmount" wire:model="chargeAmount" :label="__('Amount')" />

                        <div class="grid gap-1">
                            <label for="charge-currency" class="text-sm text-fg-ink">{{ __('Currency') }}</label>
                            <x-ui.select name="chargeCurrency" id="charge-currency" wire:model="chargeCurrency">
                                @foreach ($this->currencyOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="chargeRate" wire:model="chargeRate" :label="__('Rate to :currency (1 foreign = ?)', ['currency' => $this->baseCurrency()])" type="number" step="0.00000001" />

                        <div class="grid gap-1">
                            <label for="charge-rate-source" class="text-sm text-fg-ink">{{ __('Where the rate came from') }}</label>
                            <x-ui.select name="chargeRateSource" id="charge-rate-source" wire:model="chargeRateSource">
                                @foreach ($this->rateSourceOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="chargeOccurredOn" wire:model="chargeOccurredOn" :label="__('What date was it paid')" type="date" />

                        <x-ui.input name="chargeDescription" wire:model="chargeDescription" :label="__('Note (optional)')" />
                    </div>

                    <p class="text-xs text-fg-ink-muted">
                        {{ __('The charge posts to the ledger in this step, as a border cost on the trip — the eight §5.2 columns are copied from that posting, so the gate and the books cannot disagree.') }}
                    </p>

                    @error('charge')
                        <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
                    @enderror

                    <div class="flex items-center justify-between gap-3">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="captureCharge">
                            <span wire:loading.remove wire:target="captureCharge">{{ __('Capture and post') }}</span>
                            <span wire:loading wire:target="captureCharge">{{ __('Posting…') }}</span>
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-border-charge')">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.dialog>
        @endif
    @endif

    {{--
        §6.6's money on one screen. Three abilities shape it and none of them widens another: raising a
        cost is `expenses.create`, seeing what the trip earned is `trips.margin.view`, and the rows a
        customer will be billed for are `invoices.view`. A dispatcher holding only the last one sees the
        billing rows and no margin at all.
    --}}
    @if ($this->canSeeMoney())
        <section class="grid gap-5 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-ui.subheading>{{ __('Money') }}</x-ui.subheading>

                @if ($this->canAddCosts())
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-trip-cost')">{{ __('Record a cost') }}</x-ui.button>
                @endif
            </div>

            @if ($this->canSeeMargin())
                @php($money = $this->trip())

                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-fg-ink-muted">{{ __('Revenue posted') }}</dt>
                        <dd class="fg-numeric text-fg-ink">{{ $this->money($money->revenue_base, $this->baseCurrency()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fg-ink-muted">{{ __('Direct costs') }}</dt>
                        <dd class="fg-numeric text-fg-ink">{{ $this->money($money->direct_cost_base, $this->baseCurrency()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fg-ink-muted">{{ __('Contribution') }}</dt>
                        <dd class="fg-numeric font-medium {{ $money->contribution_base < 0 ? 'text-danger' : 'text-fg-ink' }}">{{ $this->money($money->contribution_base, $this->baseCurrency()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fg-ink-muted">{{ __('Read from the ledger') }}</dt>
                        <dd class="text-fg-ink">{{ $this->at($money->ledger_posted_at) }}</dd>
                    </div>
                </dl>

                <div class="grid gap-2">
                    <p class="text-xs text-fg-ink-muted">{{ __('Direct costs by bucket') }}</p>

                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        @foreach ($this->bucketRows() as $bucket)
                            <span class="text-fg-ink-secondary">
                                {{ $bucket['label'] }}
                                <span class="fg-numeric ml-1 text-fg-ink">{{ $this->money($bucket['amount'], $this->baseCurrency()) }}</span>
                            </span>
                        @endforeach
                    </div>

                    <p class="text-xs text-fg-ink-muted">{{ __('Revenue stays at zero until Phase 4 raises the invoice, so this contribution is costs alone — not a margin yet (§20.17).') }}</p>
                </div>
            @endif

            @if ($this->canRecordCosts())
                @if ($this->tripCosts === [])
                    <x-ui.text>{{ __('No cost has been recorded against this trip.') }}</x-ui.text>
                @else
                    <x-ui.table :caption="__('Every cost recorded against this trip, and what it was paid with')">
                        <x-slot name="head">
                            <x-ui.table.th>{{ __('Date') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('Cost') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('Paid with') }}</x-ui.table.th>
                            <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                            <x-ui.table.th numeric>{{ __('In :currency', ['currency' => $this->baseCurrency()]) }}</x-ui.table.th>
                        </x-slot>

                        @foreach ($this->tripCosts as $cost)
                            <tr wire:key="cost-{{ $cost->id }}">
                                <x-ui.table.td nowrap>{{ $cost->occurred_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                                <x-ui.table.td>
                                    {{ $cost->cost_type->label() }}
                                    @if ($cost->description !== null)
                                        <span class="text-fg-ink-muted">· {{ $cost->description }}</span>
                                    @endif
                                </x-ui.table.td>
                                <x-ui.table.td>{{ $cost->paid_with->label() }}</x-ui.table.td>
                                <x-ui.table.td numeric>{{ $this->money($cost->amount, $cost->currency_code) }}</x-ui.table.td>
                                <x-ui.table.td numeric>{{ $this->money($cost->base_amount, $cost->base_currency_code) }}</x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif

                @error('cost')
                    <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
                @enderror

                @if ($this->costsAreFrozen())
                    <x-ui.alert tone="info" :title="__('Costs are final')">
                        {{ __('This trip is closed, cancelled or aborted, so no cost may be added. Re-open it, or correct the ledger with a reversing entry (§6.4).') }}
                    </x-ui.alert>
                @endif
            @endif

            @if ($this->canSeeBilling())
                <div class="grid gap-3 border-t border-fg-line pt-4">
                    <x-ui.subheading>{{ __('Ready to bill') }}</x-ui.subheading>

                    @if ($this->chargeables === [])
                        <x-ui.text>{{ __('Nothing has been earned yet — a chargeable is built when the delivery proof is accepted (§16).') }}</x-ui.text>
                    @else
                        <x-ui.table :caption="__('What this trip has earned and whether each chargeable can go on an invoice yet')">
                            <x-slot name="head">
                                <x-ui.table.th>{{ __('Earned') }}</x-ui.table.th>
                                <x-ui.table.th>{{ __('Basis') }}</x-ui.table.th>
                                <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                                <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                                <x-ui.table.th numeric>{{ __('In :currency', ['currency' => $this->baseCurrency()]) }}</x-ui.table.th>
                            </x-slot>

                            @foreach ($this->chargeables as $chargeable)
                                <tr wire:key="chargeable-{{ $chargeable->id }}">
                                    <x-ui.table.td>{{ $chargeable->type->label() }}</x-ui.table.td>
                                    <x-ui.table.td>{{ $chargeable->basis->label() }}</x-ui.table.td>
                                    <x-ui.table.td><x-ui.badge :tone="$chargeable->status->tone()">{{ $chargeable->status->label() }}</x-ui.badge></x-ui.table.td>
                                    <x-ui.table.td numeric>{{ $this->money($chargeable->amount, $chargeable->currency_code) }}</x-ui.table.td>
                                    <x-ui.table.td numeric>{{ $this->money($chargeable->base_amount, $this->baseCurrency()) }}</x-ui.table.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </div>
            @endif
        </section>

        {{-- The cost form. A foreign amount asks for its rate; the base currency is rate 1, always (§5.2). --}}
        @if ($this->canAddCosts())
            <x-ui.dialog name="trip-cost" :title="__('Record a trip cost')">
                <form wire:submit="recordCost" class="grid gap-4 p-5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <label for="cost-type" class="text-sm text-fg-ink">{{ __('What was it') }}</label>
                            <x-ui.select name="costType" id="cost-type" wire:model="costType">
                                @foreach ($this->costTypeOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="grid gap-1">
                            <label for="cost-paid-with" class="text-sm text-fg-ink">{{ __('Paid with') }}</label>
                            <x-ui.select name="costPaidWith" id="cost-paid-with" wire:model="costPaidWith">
                                @foreach ($this->paymentOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="costAmount" wire:model="costAmount" :label="__('Amount')" />

                        <div class="grid gap-1">
                            <label for="cost-currency" class="text-sm text-fg-ink">{{ __('Currency') }}</label>
                            <x-ui.select name="costCurrency" id="cost-currency" wire:model="costCurrency">
                                @foreach ($this->currencyOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="costRate" wire:model="costRate" :label="__('Rate to :currency (1 foreign = ?)', ['currency' => $this->baseCurrency()])" type="number" step="0.00000001" />

                        <div class="grid gap-1">
                            <label for="cost-rate-source" class="text-sm text-fg-ink">{{ __('Where the rate came from') }}</label>
                            <x-ui.select name="costRateSource" id="cost-rate-source" wire:model="costRateSource">
                                @foreach ($this->rateSourceOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.input name="costOccurredOn" wire:model="costOccurredOn" :label="__('What date did it happen')" type="date" />

                        <x-ui.input name="costDescription" wire:model="costDescription" :label="__('Note (optional)')" />
                    </div>

                    <p class="text-xs text-fg-ink-muted">
                        {{ __('The cost posts to the ledger in this step — one expense line against the trip, one credit to the account you paid from. A BWP amount is rate 1.') }}
                    </p>

                    @error('cost')
                        <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
                    @enderror

                    <div class="flex items-center justify-between gap-3">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="recordCost">
                            <span wire:loading.remove wire:target="recordCost">{{ __('Record and post') }}</span>
                            <span wire:loading wire:target="recordCost">{{ __('Posting…') }}</span>
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-trip-cost')">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.dialog>
        @endif
    @endif

    {{-- Fuel is a module of its own (§4.3): the trip names the truck and the tank, the panel does the rest. --}}
    <livewire:pages::fuel.trip-panel :trip="$this->trip()" />

    {{--
        §9.6's two halves on one screen: the door the dispatcher opens for the driver, and the decision the
        office makes on what came back. Both are behind their own permission so a seat that can plan a trip
        but not hand out a link still sees the trip and nothing else.
    --}}
    @if ($this->canIssueDriverLink() || $this->canApprovePod())
        <section class="grid gap-5 rounded-fg-lg border border-fg-line bg-fg-surface p-5">
            <x-ui.subheading>{{ __('Driver access and delivery evidence') }}</x-ui.subheading>

            @if ($this->canIssueDriverLink())
                @php($reveal = $this->driverReveal())

                @if (is_array($reveal))
                    <x-ui.alert tone="warning" :title="__('Copy this now')">
                        <div class="grid gap-1.5">
                            <p>{{ __('Send :driver this link and PIN. Only hashes are stored, so neither can be shown again.', ['driver' => $this->driverLabel()]) }}</p>

                            <div class="flex flex-wrap items-center gap-2">
                                <p class="break-all font-mono text-xs">{{ $reveal['url'] }}</p>

                                {{-- The link is typed into a WhatsApp message to a driver standing at a gate.
                                     Selecting a wrapped URL on a phone is the thing this screen can hand over. --}}
                                <span
                                    x-data="{ copied: false }"
                                    x-on:click="navigator.clipboard
                                        ? navigator.clipboard.writeText(@js($reveal['url'])).then(() => copied = true)
                                        : window.prompt('Copy the link', @js($reveal['url']))"
                                    role="button"
                                    tabindex="0"
                                    class="cursor-pointer rounded-fg-sm border border-fg-line-strong px-2 py-1 text-xs text-accent"
                                >
                                    <span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span>
                                </span>
                            </div>

                            <p class="font-mono text-sm">{{ __('PIN :pin', ['pin' => $reveal['pin']]) }}</p>
                            <p class="text-xs">{{ __('Opens until :at', ['at' => $reveal['expires_at'] ?? __('—')]) }}</p>

                            {{--
                                §9.6's link is 48 hex characters, which is not something a driver keys on a
                                phone at a gate. The code is the same URL the line above prints, and the PIN
                                is deliberately kept out of it: the kiosk asks for it on every submission,
                                so a code that carried it would hand anyone who photographed the screen the
                                right to sign for a load.
                            --}}
                            <div class="flex flex-wrap items-start gap-3">
                                {{--
                                    The SVG arrives with the pixel size it was drawn at (208), which is wider
                                    than this box. Left alone it overflows to the right and the column the
                                    buttons live in paints over it, so the code loses a third of its modules
                                    to the very button meant to carry the link. Constraining it to the box
                                    scales the viewBox instead; the box is 10rem so the code stays legible to
                                    a phone camera held at a gate.
                                --}}
                                <div
                                    class="w-40 shrink-0 rounded-fg-sm border border-fg-line bg-white p-2 [&>svg]:block [&>svg]:h-auto [&>svg]:w-full"
                                    role="img"
                                    aria-label="{{ __('QR code of the driver link') }}"
                                >
                                    {!! $this->driverQrSvg() !!}
                                </div>

                                <div class="grid gap-1.5">
                                    <p class="text-xs">{{ __('Scan this with the phone camera, then type the PIN when the page asks for it.') }}</p>

                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button
                                            size="sm"
                                            :href="$this->driverShareUrl()"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >{{ __('Send by WhatsApp') }}</x-ui.button>

                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            :href="route('operations.trip.driver-link.qr', $this->tripId)"
                                        >{{ __('Save the code') }}</x-ui.button>
                                    </div>
                                </div>
                            </div>

                            <p class="text-xs text-fg-ink-muted">{{ __('It stops working the moment the trip closes, and the PIN is asked again on every submission.') }}</p>
                            <div class="pt-1">
                                <x-ui.button size="sm" variant="ghost" wire:click="dismissDriverReveal">{{ __('I have copied it') }}</x-ui.button>
                            </div>
                        </div>
                    </x-ui.alert>
                @endif

                @php($liveLinks = collect($this->accessTokens)->filter(static fn ($token): bool => $token->closedBecause() === null)->count())

                <form class="grid gap-3" wire:submit="issueDriverLink">
                    <div class="flex flex-wrap items-center gap-4">
                        @foreach ($this->scopeOptions() as $scope)
                            <label class="flex items-center gap-2 text-sm text-fg-ink">
                                <input type="checkbox" wire:model="kioskScopes" value="{{ $scope['value'] }}" class="rounded-fg-sm border-fg-line-strong" />
                                {{ $scope['label'] }}
                            </label>
                        @endforeach
                    </div>

                    <p class="text-xs text-fg-ink-muted">
                        {{ __('The link opens on the driver’s phone without a login, and the PIN is asked on every submission. A scope this build cannot honour is refused here rather than at the border.') }}
                    </p>

                    {{-- Issuing a second link does not close the first, and a driver with two links has two
                         PINs to guess between. Saying so here is cheaper than the phone call at the gate. --}}
                    @if ($liveLinks > 0)
                        <x-ui.alert tone="warning" :title="__('A link is already open')">
                            <p class="text-sm">
                                {{ __('This trip already has :count link still able to open. Issuing another does not close it — close the old one below if it went to the wrong person.', ['count' => $liveLinks]) }}
                            </p>
                        </x-ui.alert>
                    @endif

                    @error('kiosk')
                        <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
                    @enderror

                    <div>
                        <x-ui.button
                            type="submit"
                            variant="outline"
                            wire:loading.attr="disabled"
                            wire:target="issueDriverLink"
                        >
                            <span wire:loading.remove wire:target="issueDriverLink">{{ __('Issue a driver link') }}</span>
                            <span wire:loading wire:target="issueDriverLink">{{ __('Issuing…') }}</span>
                        </x-ui.button>
                    </div>
                </form>

                @if ($this->accessTokens !== [])
                    <x-ui.table :caption="__('The driver kiosk links issued for this trip, and when each one stops working')">
                        <x-slot name="head">
                            <x-ui.table.th>{{ __('Issued') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('Scopes') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('Expires') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('Last used') }}</x-ui.table.th>
                            <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                            <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                        </x-slot>

                        @foreach ($this->accessTokens as $token)
                            <tr wire:key="token-{{ $token->id }}">
                                <x-ui.table.td nowrap>{{ $this->at($token->created_at) }}</x-ui.table.td>
                                <x-ui.table.td>{{ $this->tokenScopes($token) }}</x-ui.table.td>
                                <x-ui.table.td nowrap>{{ $this->at($token->expires_at) }}</x-ui.table.td>
                                <x-ui.table.td nowrap>{{ $this->at($token->last_used_at) }}</x-ui.table.td>
                                <x-ui.table.td>
                                    <x-ui.badge :tone="$this->tokenTone($token)">{{ $this->tokenState($token) }}</x-ui.badge>
                                </x-ui.table.td>
                                <x-ui.table.td numeric>
                                    @if ($token->closedBecause() === null)
                                        <x-ui.button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="revokeDriverLink('{{ $token->id }}')"
                                            wire:confirm="{{ __('Close this link? The driver loses access at once, and the row stays on the trip as evidence.') }}"
                                            wire:loading.attr="disabled"
                                        >{{ __('Close the link') }}</x-ui.button>
                                    @endif
                                </x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            @endif

            @if ($this->canApprovePod())
                <div class="grid gap-3 border-t border-fg-line pt-4">
                    <x-ui.subheading>{{ __('Proof of delivery') }}</x-ui.subheading>

                    @if ($this->proofs === [])
                        <x-ui.text>{{ __('The driver has not sent a proof yet — §10.2 will not let this trip be delivered without one.') }}</x-ui.text>
                    @else
                        @foreach ($this->proofs as $pod)
                            <div wire:key="pod-{{ $pod->id }}" class="grid gap-2 rounded-fg-md border border-fg-line p-3">
                                <div class="flex flex-wrap items-center gap-2 text-sm text-fg-ink">
                                    <x-ui.badge :tone="$pod->status->tone()">{{ $pod->status->label() }}</x-ui.badge>
                                    <span>{{ $pod->receiver_name ?? __('(no receiver named)') }}</span>
                                    <span class="text-fg-ink-muted">· {{ $pod->quantity_received_tonnes_10_2 === null ? __('(no quantity)') : $pod->quantity_received_tonnes_10_2.' t' }}</span>
                                    <span class="text-fg-ink-muted">· {{ $this->at($pod->delivered_at) }}</span>
                                </div>

                                <p class="text-xs text-fg-ink-secondary">
                                    {{ $pod->signature_document_id === null
                                        ? __('No signature on file')
                                        : __('Signature on file · :id', ['id' => $pod->signature_document_id]) }}
                                </p>

                                @if (($pod->exceptions ?? []) !== [])
                                    <ul class="grid gap-0.5 text-xs text-warning">
                                        @foreach ($pod->exceptions as $key => $value)
                                            <li>
                                                · {{ is_string($key) ? $key.': ' : '' }}
                                                {{ is_array($value) ? implode(', ', array_map(static fn ($item): string => (string) $item, $value)) : (string) $value }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($this->podAwaitingDecision($pod))
                                    <div class="flex flex-wrap items-end gap-2">
                                        <x-ui.button
                                            size="sm"
                                            wire:click="approvePod('{{ $pod->id }}')"
                                            wire:confirm="{{ __('Accepting this delivery builds the billable lines from it. If a figure is wrong, send it back to the driver instead.') }}"
                                            wire:loading.attr="disabled"
                                            wire:target="approvePod"
                                        >
                                            <span wire:loading.remove wire:target="approvePod">{{ __('Accept the delivery') }}</span>
                                            <span wire:loading wire:target="approvePod">{{ __('Accepting…') }}</span>
                                        </x-ui.button>

                                        <div class="min-w-64 flex-1">
                                            <x-ui.input name="podRejectReason" wire:model="podRejectReason" :label="__('Or send it back — what is wrong? The driver sees this on their phone.')" />
                                        </div>

                                        <x-ui.button size="sm" variant="outline" wire:click="rejectPod('{{ $pod->id }}')" wire:loading.attr="disabled" wire:target="rejectPod">{{ __('Send back') }}</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @error('pod')
                            <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
                        @enderror
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- The load form. --}}
    <x-ui.dialog name="trip-load" :title="__('Add a load')">
        <form wire:submit="addLoad" class="grid gap-4 p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.input name="loadCommodity" wire:model="loadCommodity" :label="__('Commodity')" />
                <x-ui.input name="loadTonnes" wire:model="loadTonnes" :label="__('Tonnes')" type="number" step="0.01" />
                <x-ui.input name="loadCubic" wire:model="loadCubic" :label="__('Cubic metres')" type="number" step="0.01" />
                <x-ui.input name="loadConsignee" wire:model="loadConsignee" :label="__('Consignee')" />
                <x-ui.input name="loadOrigin" wire:model="loadOrigin" :label="__('Loads at')" />
                <x-ui.input name="loadDestination" wire:model="loadDestination" :label="__('Drops at')" />
                <x-ui.input name="loadStopSequence" wire:model="loadStopSequence" :label="__('Stop sequence (e.g. 1/3)')" />
            </div>

            <div class="grid gap-1">
                <label for="load-waybill" class="text-sm text-fg-ink">{{ __('Waybill (filed paper)') }}</label>
                <x-ui.select name="loadWaybill" id="load-waybill" wire:model="loadWaybill">
                    <option value="">{{ __('None yet — loading will refuse this line') }}</option>
                    @foreach ($this->waybills as $document)
                        <option value="{{ $document->id }}">{{ $document->title }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            @error('load')
                <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit">{{ __('Add it') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-trip-load')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- The reason form: hold, cancel/abort and re-open each ask for one (§10.2, §4.4), and the box now
         knows which of them it was opened for. --}}
    <x-ui.dialog name="trip-reason" :title="$this->reasonTitle()">
        <div class="grid gap-4 p-5">
            <p class="text-sm text-fg-ink-secondary">{{ $this->reasonHelper() }}</p>

            <x-ui.input name="reason" wire:model="reason" :label="__('Reason — it goes on the trip’s trail, where the auditors will read it.')" />

            @error('reason')
                <x-ui.alert tone="danger" :title="__('That did not go through')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex flex-wrap items-center gap-2">
                @if ($this->reasonAction() !== '')
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="{{ $this->reasonAction() }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $this->reasonAction() }}"
                    >
                        <span wire:loading.remove wire:target="{{ $this->reasonAction() }}">{{ $this->reasonTitle() }}</span>
                        <span wire:loading wire:target="{{ $this->reasonAction() }}">{{ __('Working…') }}</span>
                    </x-ui.button>
                @endif

                <x-ui.button size="sm" variant="ghost" x-on:click="$dispatch('close-trip-reason')">{{ __('Leave it as it is') }}</x-ui.button>
            </div>
        </div>
    </x-ui.dialog>
</div>
