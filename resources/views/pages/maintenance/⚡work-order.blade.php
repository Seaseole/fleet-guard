<?php

declare(strict_types=1);

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Maintenance\Actions\AddWorkOrderLine;
use App\Domain\Maintenance\Actions\ApproveWorkOrder;
use App\Domain\Maintenance\Actions\CancelWorkOrder;
use App\Domain\Maintenance\Actions\CloseWorkOrder;
use App\Domain\Maintenance\Actions\CompleteWorkOrder;
use App\Domain\Maintenance\Actions\HoldWorkOrder;
use App\Domain\Maintenance\Actions\InspectWorkOrder;
use App\Domain\Maintenance\Actions\IssuePartToWorkOrder;
use App\Domain\Maintenance\Actions\PlanWorkOrder;
use App\Domain\Maintenance\Actions\RejectWorkOrder;
use App\Domain\Maintenance\Actions\RemoveWorkOrderLine;
use App\Domain\Maintenance\Actions\ResumeWorkOrder;
use App\Domain\Maintenance\Actions\StartWorkOrder;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\Models\Part;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\ServiceScheduleKind;
use App\Domain\Maintenance\WorkOrderLineKind;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One work order — where the estimate becomes a job, and the job becomes a cost.
 *
 * The screen is the state machine's own face. Every button exists because the transition table says
 * the move is there, and every button is gated by the seat the design gives that move: the workshop raises,
 * plans, starts, holds, completes and inspects; only `work_orders.approve` may bless the spend above
 * the limit; only `work_orders.close` may freeze the costs and decide what is an asset.
 *
 * The lines stop being editable at `in_progress` — the rule, enforced by the Actions and printed
 * here as a plain sentence rather than a greyed table. After that the costs are what the work took,
 * and the paths that write them arrive slice by slice: parts now leave the stores shelf into this
 * job (costed at the shelf's average, posted to 5310 or 5320 against the truck and the job), while
 * timesheets and supplier bills are still to come.
 *
 * Nothing on this screen is a state transition that touches the ledger; the part issue is the one
 * costing path that posts, and it posts where the movement is recorded rather than at close (
 * "posting is not a state").
 */
new #[Title('Work order')] class extends Component {
    use ConfirmsRecentPassword;
    /** The ULID, held as a key: the Livewire snapshot is client-readable, and a stale model would act on a row the page no longer shows. */
    public string $workOrderId = '';

    public string $lineKind = WorkOrderLineKind::Labour->value;

    public string $lineDescription = '';

    public string $lineQuantity = '';

    public string $lineUnitCost = '';

    public bool $lineCapital = false;

    public string $rejectReason = '';

    public string $cancelReason = '';

    public string $plannedFor = '';

    public string $holdReason = HoldReason::AwaitingParts->value;

    public string $holdNote = '';

    public string $completionOdometer = '';

    public string $completionHours = '';

    public bool $closeCapitalise = false;

    public string $closeAmount = '';

    public string $issuePartId = '';

    public string $issueQuantity = '';

    public string $issueDate = '';

    public string $issueNote = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrderId = $workOrder->id;

        $truck = $workOrder->truck;

        $this->completionOdometer = $truck === null ? '' : (string) $truck->odometer_km;
        $this->completionHours = $truck === null || $truck->engine_hours_10_2 === null
            ? ''
            : number_format((float) $truck->engine_hours_10_2, 2, '.', '');
        $this->plannedFor = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->issueDate = Carbon::now(CurrentCompany::timezone())->toDateString();
    }

    public function canCreate(): bool
    {
        return Gate::allows('work_orders.create');
    }

    public function canApprove(): bool
    {
        return Gate::allows('work_orders.approve');
    }

    public function canClose(): bool
    {
        return Gate::allows('work_orders.close');
    }

    /* ------------------------------------------------------------------ lines */

    public function addLine(AddWorkOrderLine $add): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate([
            'lineKind' => ['required'],
            'lineDescription' => ['required', 'string', 'max:160'],
            'lineQuantity' => ['required', 'string'],
            'lineUnitCost' => ['required', 'string'],
        ]);

        try {
            $add($this->order, new WorkOrderLineDto(
                kind: WorkOrderLineKind::from($this->lineKind),
                description: $this->lineDescription,
                quantityX100: $this->scaledHundredths($this->lineQuantity, __('Quantity')),
                unitCostMinor: $this->scaledHundredths($this->lineUnitCost, __('Unit cost')),
                currencyCode: $this->baseCurrency(),
                capitalCandidate: $this->lineCapital,
            ), (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('line', $refused->getMessage());

            return;
        }

        $this->reset(['lineDescription', 'lineQuantity', 'lineUnitCost', 'lineCapital']);
        $this->lineKind = WorkOrderLineKind::Labour->value;

        $this->changed(__('Line added — the estimate and the approval limit have been re-checked.'), 'wo-line');
    }

    public function removeLine(int $lineId, RemoveWorkOrderLine $remove): void
    {
        abort_unless($this->canCreate(), 403);

        $line = WorkOrderLine::query()->where('work_order_id', $this->workOrderId)->find($lineId);

        if ($line === null) {
            $this->addError('line', __('That line is no longer on this order.'));

            return;
        }

        try {
            $remove($this->order, $line, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('line', $refused->getMessage());

            return;
        }

        $this->changed(__('Line removed.'), clearErrors: 'line');
    }

    /* ------------------------------------------------------------------ parts */

    /**
     * Whether the shelf may feed this job: the seat holds `work_orders.create`, and the machine must
     * be at a point where the work is sanctioned — a draft or an estimate waiting for an approver may
     * not draw stock.
     */
    public function canIssueParts(): bool
    {
        return $this->canCreate() && in_array($this->order->status, [
            WorkOrderStatus::Approved,
            WorkOrderStatus::Planned,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::OnHold,
        ], true);
    }

    public function issuePart(IssuePartToWorkOrder $issue): void
    {
        abort_unless($this->canIssueParts(), 403);

        $this->validate([
            'issuePartId' => ['required', 'integer'],
            'issueQuantity' => ['required', 'string'],
            'issueDate' => ['required', 'date'],
            'issueNote' => ['nullable', 'string', 'max:160'],
        ]);

        $part = Part::query()->find((int) $this->issuePartId);

        if ($part === null) {
            $this->addError('issue', __('That part is no longer in the catalogue.'));

            return;
        }

        try {
            $issue(
                $this->order,
                $part,
                $this->scaledHundredths($this->issueQuantity, __('Quantity')),
                Carbon::parse($this->issueDate),
                $this->issueNote,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('issue', $refused->getMessage());

            return;
        }

        $this->reset(['issuePartId', 'issueQuantity', 'issueNote']);

        $this->changed(
            __('Issued — the shelf moved, the job’s parts cost moved, and both sides posted together.'),
            'issue-part',
            'issue',
        );
    }

    /* --------------------------------------------------------------- decisions */

    public function approve(ApproveWorkOrder $approve): void
    {
        abort_unless($this->canApprove(), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        try {
            $approve($this->order, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('Approved — the job may be planned or started.'), 'wo-approve');
    }

    public function reject(RejectWorkOrder $reject): void
    {
        abort_unless($this->canApprove(), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        $this->validate(['rejectReason' => ['required', 'string', 'max:160']]);

        try {
            $reject($this->order, $this->rejectReason, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->reset('rejectReason');
        $this->changed(__('Rejected — the workshop may re-quote.'), 'wo-reject');
    }

    public function plan(PlanWorkOrder $plan): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate(['plannedFor' => ['required', 'date']]);

        try {
            $plan($this->order, $this->plannedFor, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('Planned into the diary.'), 'wo-plan');
    }

    public function start(StartWorkOrder $start): void
    {
        abort_unless($this->canCreate(), 403);

        try {
            $start($this->order, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('In the workshop — the truck is off the road until the vehicle is released.'));
    }

    public function hold(HoldWorkOrder $hold): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate([
            'holdReason' => ['required'],
            'holdNote' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $hold(
                $this->order,
                HoldReason::from($this->holdReason),
                trim($this->holdNote) === '' ? null : $this->holdNote,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->reset('holdNote');
        $this->changed(__('Stopped — the reason is on the trail.'), 'wo-hold');
    }

    public function resume(ResumeWorkOrder $resume): void
    {
        abort_unless($this->canCreate(), 403);

        try {
            $resume($this->order, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('Back under way.'));
    }

    public function complete(CompleteWorkOrder $complete): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate([
            'completionOdometer' => ['required', 'integer', 'min:1'],
            'completionHours' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $complete(
                $this->order,
                (int) $this->completionOdometer,
                trim($this->completionHours) === '' ? null : (float) $this->completionHours,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('Completed — the vehicle is released and the reading is on the truck.'), 'wo-complete');
    }

    public function inspect(InspectWorkOrder $inspect): void
    {
        abort_unless($this->canCreate(), 403);

        try {
            $inspect($this->order, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->changed(__('Inspected — the costs can now be closed.'));
    }

    public function close(CloseWorkOrder $close): void
    {
        abort_unless($this->canClose(), 403);

        $this->validate(['closeAmount' => ['nullable', 'string']]);

        try {
            $amount = trim($this->closeAmount) === ''
                ? null
                : $this->scaledHundredths($this->closeAmount, __('Capitalised amount'));

            $close($this->order, $this->closeCapitalise, $amount, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->reset(['closeCapitalise', 'closeAmount']);
        $this->changed(__('Closed — the costs are frozen and the capitalise decision is recorded.'), 'wo-close');
    }

    public function cancel(CancelWorkOrder $cancel): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate(['cancelReason' => ['required', 'string', 'max:160']]);

        try {
            $cancel($this->order, $this->cancelReason, (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->reset('cancelReason');
        $this->changed(__('Withdrawn — the reason is on the trail.'), 'wo-cancel');
    }

    /* ------------------------------------------------------------------ reads */

    #[Computed]
    public function order(): WorkOrder
    {
        return WorkOrder::query()
            ->with(['truck', 'schedule', 'mechanic', 'supplier'])
            ->findOrFail($this->workOrderId);
    }

    /** @return list<\App\Domain\Kernel\Audit\Models\OperationalEvent> */
    #[Computed]
    public function trail(): array
    {
        return \App\Domain\Kernel\Audit\Models\OperationalEvent::query()
            ->where('aggregate_type', 'work_order')
            ->where('aggregate_id', $this->workOrderId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** The same truck's other orders — the service history the register is read for. */
    #[Computed]
    public function siblings(): array
    {
        return WorkOrder::query()
            ->with('truck')
            ->where('truck_id', $this->order->truck_id)
            ->whereKeyNot($this->workOrderId)
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->all();
    }

    /** @return list<WorkOrderLineKind> */
    public function lineKinds(): array
    {
        return WorkOrderLineKind::cases();
    }

    /** @return list<HoldReason> */
    public function holdReasons(): array
    {
        return HoldReason::cases();
    }

    /**
     * The shelf this job may draw from: active parts with something on hand that either fit any truck
     * or are specific to this one. A part belonging to another truck is never offered, because the
     * Action would refuse it anyway and a list of refusals is not a picker.
     *
     * @return list<Part>
     */
    public function issuePartOptions(): array
    {
        return Part::query()
            ->where('active', true)
            ->where('stock_quantity_x100', '>', 0)
            ->where(fn ($query) => $query->whereNull('truck_id')->orWhere('truck_id', $this->order->truck_id))
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** @return list<\App\Domain\Maintenance\Models\StockMovement> */
    public function issuedParts(): array
    {
        return $this->order->issuedParts()->with('part')->get()->all();
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function money(Money $money): string
    {
        return $money->format($this->baseCurrency());
    }

    public function approverName(): ?string
    {
        $order = $this->order;

        if ($order->approved_by === null) {
            return null;
        }

        return User::query()->whereKey($order->approved_by)->value('name');
    }

    public function scheduleIsHours(): bool
    {
        return $this->order->schedule?->kind === ServiceScheduleKind::Hours;
    }

    /** The flagged lines' sum — what a close capitalises when no explicit amount is given. */
    public function flaggedCapital(): Money
    {
        $flagged = 0;

        foreach ($this->order->costLines() as $line) {
            if ($line->capital_candidate) {
                $flagged += $line->base_amount;
            }
        }

        return Money::ofMinor($flagged, $this->baseCurrency());
    }

    public function canEditLines(): bool
    {
        return $this->canCreate() && $this->order->status->acceptsLines();
    }

    /* The button of each move, as one question: the seat holds the gate and the machine is at the
       point where the move exists. The Blade condition then names the move, not the enum. */

    public function canApproveNow(): bool
    {
        return $this->canApprove() && $this->order->status === WorkOrderStatus::AwaitingApproval;
    }

    public function canRejectNow(): bool
    {
        return $this->canApprove() && $this->order->status === WorkOrderStatus::AwaitingApproval;
    }

    public function canPlanNow(): bool
    {
        return $this->canCreate() && $this->order->status === WorkOrderStatus::Approved;
    }

    public function canWithdrawNow(): bool
    {
        return $this->canCreate() && in_array($this->order->status, [
            WorkOrderStatus::Draft,
            WorkOrderStatus::Approved,
            WorkOrderStatus::Planned,
        ], true);
    }

    public function canStartNow(): bool
    {
        return $this->canCreate() && in_array($this->order->status, [
            WorkOrderStatus::Approved,
            WorkOrderStatus::Planned,
        ], true);
    }

    public function canHoldNow(): bool
    {
        return $this->canCreate() && $this->order->status === WorkOrderStatus::InProgress;
    }

    public function canCompleteNow(): bool
    {
        return $this->canCreate() && $this->order->status === WorkOrderStatus::InProgress;
    }

    public function canResumeNow(): bool
    {
        return $this->canCreate() && $this->order->status === WorkOrderStatus::OnHold;
    }

    public function canInspectNow(): bool
    {
        return $this->canCreate() && $this->order->status === WorkOrderStatus::Completed;
    }

    public function canCloseNow(): bool
    {
        return $this->canClose() && $this->order->status === WorkOrderStatus::Inspected;
    }

    public function isClosed(): bool
    {
        return $this->order->status === WorkOrderStatus::Closed;
    }

    /**
     * The one affirmative action this state offers, or null when the machine has nothing left to do.
     *
     * The design allows exactly one filled button per view, and which move that is depends on the state. So the
     * page asks this method rather than printing six primaries behind six conditions — the lint reads
     * the source, and a static `variant="primary"` per branch is six primaries to it whatever the
     * render does. Moves that need a reason or a date open their dialog; the rest are one round trip.
     *
     * @return array{label: string, click: string}|null
     */
    public function primaryAction(): ?array
    {
        return match (true) {
            $this->canApproveNow() => ['label' => __('Approve the spend'), 'click' => "\$dispatch('open-wo-approve')"],
            $this->canStartNow() => ['label' => __('Start the job'), 'click' => '$wire.start()'],
            $this->canCompleteNow() => ['label' => __('Release the vehicle'), 'click' => "\$dispatch('open-wo-complete')"],
            $this->canResumeNow() => ['label' => __('Resume the job'), 'click' => '$wire.resume()'],
            $this->canInspectNow() => ['label' => __('Inspect the work'), 'click' => '$wire.inspect()'],
            $this->canCloseNow() => ['label' => __('Close and freeze the costs'), 'click' => "\$dispatch('open-wo-close')"],
            default => null,
        };
    }

    private function changed(string $message, ?string $closeDialog = null, ?string $clearErrors = null): void
    {
        unset($this->order, $this->trail, $this->siblings);

        if ($clearErrors !== null) {
            $this->resetValidation($clearErrors);
        }

        if ($closeDialog !== null) {
            $this->dispatch('close-'.$closeDialog);
        }

        $this->dispatch('toast', message: $message, kind: 'success');
    }

    /** "45.32" → 4532, without ever building a float. */
    private function scaledHundredths(string $input, string $label): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__(':field must be a number with at most two decimals.', ['field' => $label]));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.heading size="xl" level="1">{{ $this->order->wo_no }}</x-ui.heading>
                <x-ui.badge :tone="$this->order->status->tone()">{{ $this->order->status->label() }}</x-ui.badge>
                <x-ui.badge tone="neutral">{{ $this->order->kind->label() }}</x-ui.badge>
            </div>

            <x-ui.text>{{ $this->order->title }}</x-ui.text>

            <x-ui.text size="sm" color="muted">
                <a href="{{ route('fleet.show', ['truck' => $this->order->truck_id]) }}" wire:navigate class="text-accent-ink hover:underline">{{ $this->order->truck?->label() ?? __('Truck #:id', ['id' => $this->order->truck_id]) }}</a>
                · {{ __('opened :date', ['date' => $this->order->opened_on?->format('d M Y') ?? '—']) }}
                @if ($this->order->planned_for !== null)
                    · {{ __('planned for :date', ['date' => $this->order->planned_for->format('d M Y')]) }}
                @endif
            </x-ui.text>
        </div>

        <x-ui.button variant="ghost" :href="route('maintenance.index')">{{ __('Back to the register') }}</x-ui.button>
    </header>

    {{-- Whatever the status allows. The filled button is the one move this state is asking for; the rest
         are outline, and the dialogs print the reason the machine would give before it refuses. --}}
    <div class="flex flex-wrap items-center gap-2">
        @if ($primary = $this->primaryAction())
            <x-ui.button variant="primary" x-on:click="{{ $primary['click'] }}">{{ $primary['label'] }}</x-ui.button>
        @endif

        @if ($this->canRejectNow())
            <x-ui.button variant="danger" x-on:click="$dispatch('open-wo-reject')">{{ __('Reject') }}</x-ui.button>
        @endif

        @if ($this->canPlanNow())
            <x-ui.button x-on:click="$dispatch('open-wo-plan')">{{ __('Plan into the diary') }}</x-ui.button>
        @endif

        @if ($this->canHoldNow())
            <x-ui.button x-on:click="$dispatch('open-wo-hold')">{{ __('Stop the job') }}</x-ui.button>
        @endif

        @if ($this->canWithdrawNow())
            <x-ui.button x-on:click="$dispatch('open-wo-cancel')">{{ __('Withdraw') }}</x-ui.button>
        @endif
    </div>

    @error('action')
        <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
    @enderror

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        {{-- What the order is, and the money it is accountable for. --}}
        <section class="grid gap-4 fg-panel p-5">
            <x-ui.subheading>{{ __('The job') }}</x-ui.subheading>

            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Mechanic') }}</dt>
                    <dd class="text-fg-ink">{{ $this->order->mechanic?->name ?? __('Unassigned') }}</dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('External garage') }}</dt>
                    <dd class="text-fg-ink">{{ $this->order->supplier?->label() ?? __('In-house') }}</dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Service schedule') }}</dt>
                    <dd class="text-fg-ink">
                        @if ($this->order->schedule !== null)
                            {{ $this->order->schedule->name }}
                            <span class="block text-xs text-fg-ink-muted">{{ __('next due :point', ['point' => $this->order->schedule->dueLabel()]) }}</span>
                        @else
                            {{ __('None') }}
                        @endif
                    </dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Estimated hours') }}</dt>
                    <dd class="text-fg-ink">{{ $this->order->estimated_hours_10_2 === null ? '—' : number_format((float) $this->order->estimated_hours_10_2, 2, '.', '') }}</dd>
                </div>

                @if ($this->order->completion_odometer_km !== null)
                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Odometer at release') }}</dt>
                        <dd class="text-fg-ink">{{ number_format($this->order->completion_odometer_km) }} km</dd>
                    </div>
                @endif

                @if ($this->order->hold_reason !== null)
                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('On hold') }}</dt>
                        <dd class="text-fg-ink">
                            {{ $this->order->hold_reason->label() }}
                            @if ($this->order->hold_note !== null)
                                <span class="block text-xs text-fg-ink-muted">{{ $this->order->hold_note }}</span>
                            @endif
                        </dd>
                    </div>
                @endif

                @if ($this->order->closed_at !== null)
                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Closed') }}</dt>
                        <dd class="text-fg-ink">{{ $this->order->closed_at->timezone(CurrentCompany::timezone())->format('d M Y H:i') }}</dd>
                    </div>
                @endif
            </dl>

            @if ($this->order->description !== null)
                <x-ui.text size="sm" color="muted">{{ $this->order->description }}</x-ui.text>
            @endif
        </section>

        <section class="grid gap-4 fg-panel p-5">
            <x-ui.subheading>{{ __('Costs') }}</x-ui.subheading>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Labour') }}</dt>
                    <dd class="text-fg-ink tabular-nums">{{ $this->money($this->order->labourTotal()) }}</dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Parts and consumables') }}</dt>
                    <dd class="text-fg-ink tabular-nums">{{ $this->money($this->order->partsTotal()) }}</dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('External work') }}</dt>
                    <dd class="text-fg-ink tabular-nums">{{ $this->money($this->order->externalTotal()) }}</dd>
                </div>

                <div class="grid gap-0.5">
                    <dt class="text-xs text-fg-ink-muted">{{ __('Total') }}</dt>
                    <dd class="font-medium text-fg-ink tabular-nums">{{ $this->money($this->order->total()) }}</dd>
                </div>
            </dl>

            <div class="grid gap-1 border-t border-fg-line pt-3 text-xs text-fg-ink-muted">
                @if ($this->order->neededApproval())
                    <span>
                        {{ __('Above the :limit limit in force when it was raised.', ['limit' => $this->money(Money::ofMinor($this->order->approval_required_amount_base, $this->baseCurrency()))]) }}
                    </span>
                    <span>
                        @if ($this->order->approved_by === null)
                            {{ __('Waiting on an approver.') }}
                        @else
                            {{ __('Approved by :name on :date.', [
                                'name' => $this->approverName() ?? __('an approver'),
                                'date' => $this->order->approved_at?->timezone(CurrentCompany::timezone())->format('d M Y H:i') ?? '—',
                            ]) }}
                        @endif
                    </span>
                @else
                    <span>{{ __('Under the approval limit — no signature was needed.') }}</span>
                @endif

                @if ($this->order->capitalised)
                    <span>{{ __(':amount of this spend was capitalised at close.', ['amount' => $this->money($this->order->capitalisedAmount())]) }}</span>
                @elseif ($this->isClosed())
                    <span>{{ __('Closed as an expense — nothing was capitalised.') }}</span>
                @endif
            </div>
        </section>
    </div>

    {{-- The lines. Editable until the vehicle is in the workshop; frozen from `in_progress` on. --}}
    <x-ui.card :title="__('Estimate lines')" class="min-w-0">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-ui.text size="sm" color="muted">
                @if ($this->canEditLines())
                    {{ __('The lines are the estimate the approval limit measured. Adding or removing one re-checks it: an approved order that grows past the limit goes back to an approver.') }}
                @else
                    {{ __('The lines are fixed from the moment the vehicle is in the workshop. The costs that followed are what the work took.') }}
                @endif
            </x-ui.text>

            @if ($this->canEditLines())
                <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-wo-line')">{{ __('Add a line') }}</x-ui.button>
            @endif
        </div>

        @error('line')
            <x-ui.alert tone="danger" :title="__('Refused')" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror

        <x-ui.table :caption="__('The work order’s cost lines — kind, description, quantity, unit cost and the amount each contributes')">
            <x-slot name="head">
                <x-ui.table.th>{{ __('Kind') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('What') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Qty') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Unit cost') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                <x-ui.table.th hideBelow="lg">{{ __('Capital') }}</x-ui.table.th>
                @if ($this->canEditLines())
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                @endif
            </x-slot>

            @forelse ($this->order->costLines() as $line)
                <tr wire:key="line-{{ $line->id }}">
                    <x-ui.table.td>
                        <x-ui.badge tone="neutral">{{ $line->kind->label() }}</x-ui.badge>
                    </x-ui.table.td>
                    <x-ui.table.td>{{ $line->description }}</x-ui.table.td>
                    <x-ui.table.td numeric nowrap>{{ $line->quantityLabel() }}</x-ui.table.td>
                    <x-ui.table.td numeric nowrap>{{ $this->money(Money::ofMinor($line->unit_cost_minor, $line->currency_code)) }}</x-ui.table.td>
                    <x-ui.table.td numeric nowrap>{{ $this->money($line->amount()) }}</x-ui.table.td>
                    <x-ui.table.td hideBelow="lg">
                        @if ($line->capital_candidate)
                            <x-ui.badge tone="info">{{ __('candidate') }}</x-ui.badge>
                        @else
                            <span class="text-xs text-fg-ink-muted">—</span>
                        @endif
                    </x-ui.table.td>
                    @if ($this->canEditLines())
                        <x-ui.table.td numeric nowrap>
                            <x-ui.button size="sm" variant="ghost" wire:click="removeLine({{ $line->id }})">{{ __('Remove') }}</x-ui.button>
                        </x-ui.table.td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $this->canEditLines() ? 7 : 6 }}">
                        <x-ui.empty-state
                            icon="calculator"
                            title="{{ __('No lines on this order') }}"
                            description="{{ __('An order needs at least one line — the estimate is what the approval limit is measured against.') }}"
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- What actually left the shelf for this job: the issue book's rows, costed when they were taken. --}}
    @if ($this->canIssueParts() || $this->issuedParts() !== [])
        <x-ui.card :title="__('Parts used')" class="min-w-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <x-ui.text size="sm" color="muted">
                    {{ __('A part is costed at the shelf’s moving average the moment it leaves, so a later receipt cannot re-price work already done. The stock account is credited in the same transaction.') }}
                </x-ui.text>

                @if ($this->canIssueParts())
                    <x-ui.button size="sm" variant="outline" x-on:click="$dispatch('open-issue-part')">{{ __('Issue a part') }}</x-ui.button>
                @endif
            </div>

            @error('issue')
                <x-ui.alert tone="danger" :title="__('Refused')" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.table :caption="__('Parts issued to this work order, oldest first')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Part') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Qty') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Unit cost at issue') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Issued') }}</x-ui.table.th>
                </x-slot>

                @forelse ($this->issuedParts() as $movement)
                    <tr wire:key="issue-{{ $movement->id }}">
                        <x-ui.table.td>
                            <span class="text-fg-ink">{{ $movement->part?->name ?? __('a retired part') }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $movement->part?->sku }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ number_format(abs($movement->quantity_delta_x100) / 100, 2, '.', '') }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            {{ $movement->unit_cost_minor === null ? '—' : $this->money(Money::ofMinor($movement->unit_cost_minor, $this->baseCurrency())) }}
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ $this->money(Money::ofMinor(abs($movement->value_delta_base), $this->baseCurrency())) }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $movement->moved_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-ui.empty-state
                                icon="cube"
                                title="{{ __('Nothing issued yet') }}"
                                description="{{ __('When the job needs a part, issue it from the shelf here — the stock account moves in the same step as the cost.') }}"
                            />
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- The machine's own record, in the order the engine wrote it. --}}
    <section class="grid gap-4 fg-panel p-5">
        <x-ui.subheading>{{ __('Trail') }}</x-ui.subheading>

        @if ($this->trail === [])
            <x-ui.text>{{ __('Nothing has been recorded against this order yet.') }}</x-ui.text>
        @else
            <ol class="grid gap-3">
                @foreach ($this->trail as $event)
                    <li class="grid gap-0.5 border-l-2 border-fg-line pl-3">
                        <span class="text-sm text-fg-ink">
                            {{ $event->from_state === null ? __('Opened') : __(':from →', ['from' => $event->from_state]) }}
                            <span class="font-medium">{{ $event->to_state }}</span>
                        </span>
                        <span class="text-xs text-fg-ink-muted">
                            {{ $event->occurred_at->timezone(CurrentCompany::timezone())->format('d M Y H:i') }}
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

    {{-- The same truck's history: "has this happened before" is the question a repair asks. --}}
    @if ($this->siblings !== [])
        <x-ui.card :title="__('Other work on this truck')" class="min-w-0">
            <x-ui.table :caption="__('This truck’s other work orders, newest first')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Work order') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Opened') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Cost') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </x-slot>

                @foreach ($this->siblings as $sibling)
                    <tr wire:key="sibling-{{ $sibling->id }}">
                        <x-ui.table.td>
                            <span class="text-fg-ink">{{ $sibling->wo_no }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $sibling->title }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $sibling->opened_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$sibling->status->tone()">{{ $sibling->status->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ $this->money($sibling->total()) }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            <x-ui.button size="sm" variant="ghost" :href="route('maintenance.work_order', ['workOrder' => $sibling->id])">{{ __('Open') }}</x-ui.button>
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- The approve confirmation. One sentence, because the limit is already printed above. --}}
    @can('work_orders.approve')
        <x-ui.dialog name="wo-approve" :title="__('Approve this spend')">
            <div class="grid gap-4 p-5">
                <x-ui.text>
                    {{ __('Approving says the workshop may spend :total on :title. The limit it was judged by is kept on the row, so a later change to the company limit cannot turn this into a different decision.', ['total' => $this->money($this->order->total()), 'title' => $this->order->title]) }}
                </x-ui.text>

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" wire:click="approve">{{ __('Approve') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-approve')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.dialog>

        <x-ui.dialog name="wo-reject" :title="__('Reject this estimate')">
            <form wire:submit="reject" class="grid gap-4 p-5">
                <x-ui.input name="rejectReason" wire:model="rejectReason" :label="__('Why')" :hint="__('The workshop reads this before re-quoting.')" required />

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="danger">{{ __('Reject') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-reject')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan

    @if ($this->canCreate())
        <x-ui.dialog name="wo-plan" :title="__('Plan into the diary')">
            <form wire:submit="plan" class="grid gap-4 p-5">
                <x-ui.input name="plannedFor" wire:model="plannedFor" type="date" :label="__('Day in the workshop')" required />

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Plan it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-plan')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="wo-hold" :title="__('Stop the job')">
            <form wire:submit="hold" class="grid gap-4 p-5">
                <div class="grid gap-1.5">
                    <label for="wo-hold-reason" class="text-xs font-medium text-fg-ink-secondary">{{ __('Why is it stopped') }}</label>

                    <x-ui.select name="holdReason" id="wo-hold-reason" wire:model="holdReason">
                        @foreach ($this->holdReasons() as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input name="holdNote" wire:model="holdNote" :label="__('A note (optional)')" :hint="__('What is missing, and who is chasing it.')" />

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="danger">{{ __('Stop it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-hold')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="wo-complete" :title="__('Release the vehicle')">
            <form wire:submit="complete" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('The reading at release is recorded on the truck and, when this order closes a schedule, moves the next due point one interval on.') }}
                </x-ui.text>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="completionOdometer" wire:model="completionOdometer" numeric :label="__('Odometer at release (km)')" required />

                    <x-ui.input
                        name="completionHours"
                        wire:model="completionHours"
                        numeric
                        :label="__('Engine hours')"
                        :hint="$this->scheduleIsHours() ? __('Required — the schedule is measured in engine hours.') : __('Optional.')"
                    />
                </div>

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Release it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-complete')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="wo-cancel" :title="__('Withdraw this order')">
            <form wire:submit="cancel" class="grid gap-4 p-5">
                <x-ui.input name="cancelReason" wire:model="cancelReason" :label="__('Why')" :hint="__('Kept on the trail; the truck stays in the fleet register as it is.')" required />

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="danger">{{ __('Withdraw it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-cancel')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="wo-line" :title="__('Add an estimate line')">
            <form wire:submit="addLine" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="wo-line-kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                        <x-ui.select name="lineKind" id="wo-line-kind" wire:model="lineKind">
                            @foreach ($this->lineKinds() as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="lineDescription" wire:model="lineDescription" :label="__('What')" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="lineQuantity" wire:model="lineQuantity" numeric :label="__('Quantity')" :hint="__('Hours for labour; units otherwise.')" required />
                    <x-ui.input name="lineUnitCost" wire:model="lineUnitCost" numeric :label="__('Unit cost (:currency)', ['currency' => $this->baseCurrency()])" required />
                </div>

                <x-ui.checkbox name="lineCapital" wire:model="lineCapital" :label="__('This line may be an asset — a capital candidate at close')" />

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Add it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-line')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif

    @if ($this->canClose())
        <x-ui.dialog name="wo-close" :title="__('Close and freeze the costs')">
            <form wire:submit="close" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('Closing makes the costs read-only. Nothing is posted to the ledger here — parts, labour and external work post where they were incurred.') }}
                </x-ui.text>

                <x-ui.checkbox name="closeCapitalise" wire:model="closeCapitalise" :label="__('Capitalise part of this spend as an asset')" />

                @if ($this->closeCapitalise)
                    <x-ui.input
                        name="closeAmount"
                        wire:model="closeAmount"
                        numeric
                        :label="__('Amount to capitalise (optional)')"
                        :hint="__('Blank uses the flagged lines, :amount.', ['amount' => $this->money($this->flaggedCapital())])"
                    />
                @endif

                @error('action')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Close it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-close')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif

    @if ($this->canIssueParts())
        <x-ui.dialog name="issue-part" :title="__('Issue a part to this job')">
            <form wire:submit="issuePart" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('The part leaves stores at the shelf’s moving average and is posted against this truck and job. The stock account is credited in the same transaction.') }}
                </x-ui.text>

                @php($shelf = $this->issuePartOptions())

                <x-ui.select name="issuePartId" id="wo-issue-part" wire:model="issuePartId" :label="__('Part')" required>
                    <option value="">{{ __('Choose from the shelf') }}</option>

                    @foreach ($shelf as $shelfPart)
                        <option value="{{ $shelfPart->id }}">{{ $shelfPart->label() }} — {{ __(':qty on hand', ['qty' => $shelfPart->stockLabel()]) }}</option>
                    @endforeach
                </x-ui.select>

                @if ($shelf === [])
                    <x-ui.text size="sm" color="muted">
                        {{ __('Nothing on the shelf fits this truck yet. Receive stock from the parts register first.') }}
                    </x-ui.text>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="issueQuantity" wire:model="issueQuantity" numeric :label="__('Quantity')" required />
                    <x-ui.input name="issueDate" wire:model="issueDate" type="date" :label="__('Issued on')" required />
                </div>

                <x-ui.input name="issueNote" wire:model="issueNote" :label="__('Note (optional)')" :hint="__('Where it went on the vehicle, or who took it.')" />

                @error('issue')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Issue it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-issue-part')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
