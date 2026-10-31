<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CloseJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\DeclineJob;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\Support\JobProgress;
use App\Domain\Operations\TripStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The jobs board (§3.2, §10.3) — the commercial register where freight work is opened, priced,
 * accepted and finally closed.
 *
 * Two rules shape this screen more than its columns. The first is §10.3's split: the state a person
 * sets is stored, and the four that describe the job's *children* are answered from them. So a row
 * prints "2 trips · all trips completed" as badges computed on render — never a column that can be
 * right about one trip and wrong about the next.
 *
 * The second is that a job is a promise before it is money. `accept` is refused while the job carries
 * no price, and `close` is refused while any trip is still on the road; both refusals are printed here
 * rather than hidden behind a disabled button, because the reason ("there is nothing to accept yet")
 * is the next thing the desk has to do.
 *
 * Money on this screen is the job's own quote, and it is printed for anyone §9.3 lets read the register.
 * That is a deliberate split from the route rate card, which hides its amount behind `seeCustomerTerms`:
 * the Dispatcher *writes* the quote (C / C in §9.3's row), and a price hidden from the person typing it
 * would have to be quoted blind. What stays hidden from that seat is the rate **versus** cost —
 * `jobs.rate_cost.view`, which is the margin, and which is a trip number the moment Phase 2's costs land.
 */
new #[Title('Jobs')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    /**
     * The column the register is ordered by, and which way — both in the URL, because a dispatcher who
     * sorts the list to find tonight's loads then follows a trip and comes back has not lost the view.
     */
    #[Url('sort')]
    public string $sort = 'due';

    #[Url('dir')]
    public string $dir = 'asc';

    /**
     * The worklist filter: the three states where the register is waiting on *this desk* rather than on
     * the road. `status` asks what a job is; this asks what it needs, and it is the only way to see an
     * accepted job that nobody has put a truck on yet.
     */
    #[Url('needs')]
    public string $needs = '';

    /** The row being edited as a new enquiry; null while the create dialog is empty. */
    public ?string $editingId = null;

    public string $formCustomerId = '';

    public string $formRouteId = '';

    public string $formCommodity = '';

    public string $formTonnes = '';

    public string $formLoadOn = '';

    public string $formDeliveryOn = '';

    public string $formNotes = '';

    /** The job whose dialog is open, and the fields typed into it. */
    public string $quoteJobId = '';

    public string $quoteRateType = RouteRateType::PerTrip->value;

    public string $quoteAmount = '';

    public string $quoteCurrency = '';

    public string $quoteRate = '';

    public string $quoteRateDate = '';

    public string $decisionJobId = '';

    /** `accept`, `decline` or `close` — one dialog, because all three ask for a reason-shaped answer. */
    public string $decisionKind = '';

    public string $decisionReason = '';

    public function mount(): void
    {
        $this->quoteCurrency = $this->companyCurrency();
        $this->quoteRateDate = $this->today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'sort', 'dir', 'needs'], true)) {
            $this->resetPage();
            unset($this->jobs);
        }
    }

    /**
     * Clicking a heading sorts by it; clicking the sorted one flips the direction.
     *
     * A fresh column starts in the direction that column reads top-down by — a reference and a status
     * climb, a date and a price fall, because the useful end of "latest load" and "biggest quote" is
     * the top of the screen, not the bottom.
     */
    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        $this->dir = in_array($column, ['job_no', 'status'], true) ? 'asc' : 'desc';
    }

    public function sortDirection(string $column): ?string
    {
        return $this->sort === $column ? $this->dir : null;
    }

    /** The direction actually applied — a hand-typed `?dir=anything` sorts ascending, and nothing interpolates it raw. */
    public function appliedDirection(): string
    {
        return $this->dir === 'desc' ? 'desc' : 'asc';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'needs']);
        $this->resetPage();
        unset($this->jobs);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '' || $this->needs !== '';
    }

    /** @return array<string, string> the worklist chips, in the order the desk meets them */
    #[Computed]
    public function needsOptions(): array
    {
        return [
            'quote' => __('Needs a price'),
            'answer' => __('Needs the customer'),
            'trip' => __('Needs a truck'),
        ];
    }

    /**
     * How many jobs sit behind each chip, so a dispatcher can read the queue without clicking through it.
     *
     * `needs=trip` is deliberately "no trip that still has work to do" rather than "no trip row": a job
     * whose only trip came back yesterday still needs the next one, and it is exactly the row that was
     * invisible while the register offered no filter for it.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function needsCounts(): array
    {
        $finished = [
            TripStatus::Completed->value,
            TripStatus::Closed->value,
            TripStatus::Cancelled->value,
            TripStatus::Aborted->value,
        ];

        return [
            'quote' => Job::query()->where('status', JobStatus::Enquiry->value)->count(),
            'answer' => Job::query()->where('status', JobStatus::Quoted->value)->count(),
            'trip' => Job::query()
                ->whereIn('status', [JobStatus::Accepted->value, JobStatus::InExecution->value])
                ->whereDoesntHave('trips', static fn ($q) => $q->whereNotIn('status', $finished))
                ->count(),
        ];
    }

    /** The one route the accepted job must lead to: the board, already holding this job open. */
    public function planTripUrl(Job $job): string
    {
        return route('operations.dispatch', array_filter([
            'plan_job' => $job->id,
            'on' => $job->required_load_on?->toDateString(),
        ], static fn (?string $v): bool => $v !== null && $v !== ''));
    }

    /* ---------------------------------------------------------------- create */

    public function openCreate(): void
    {
        $this->reset(['editingId', 'formCustomerId', 'formRouteId', 'formCommodity', 'formTonnes', 'formLoadOn', 'formDeliveryOn', 'formNotes']);
        $this->resetValidation();
        $this->dispatch('open-job-form');
    }

    /**
     * Closing a dialog wipes what was typed into it — server side, not just on screen.
     *
     * Livewire keeps public properties between renders, so a dialog dismissed with Escape held its half
     * typed values until the next `open*` happened to clear them. Three of these screens are opened
     * against whichever row is clicked next, so the stale half of a previous job was one careless reopen
     * away from being saved against the wrong one.
     */
    public function clearJobForm(): void
    {
        $this->reset(['editingId', 'formCustomerId', 'formRouteId', 'formCommodity', 'formTonnes', 'formLoadOn', 'formDeliveryOn', 'formNotes']);
        $this->resetValidation();
    }

    public function clearQuoteForm(): void
    {
        $this->reset(['quoteJobId', 'quoteAmount', 'quoteRate', 'quoteRateType', 'quoteRateDate']);
        $this->resetValidation();
    }

    public function clearDecisionForm(): void
    {
        $this->reset(['decisionJobId', 'decisionKind', 'decisionReason']);
        $this->resetValidation();
    }

    public function edit(string $jobId): void
    {
        $job = Job::query()->findOrFail($jobId);

        abort_unless(Gate::allows('update', $job), 403);

        $this->resetValidation();
        $this->editingId = $job->id;
        $this->formCustomerId = (string) $job->customer_id;
        $this->formRouteId = (string) $job->route_id;
        $this->formCommodity = (string) $job->commodity;
        $this->formTonnes = $job->tonnes_10_2 === null ? '' : (string) $job->tonnes_10_2;
        $this->formLoadOn = $job->required_load_on?->toDateString() ?? '';
        $this->formDeliveryOn = $job->required_delivery_on?->toDateString() ?? '';
        $this->formNotes = (string) $job->notes;

        $this->dispatch('open-job-form');
    }

    public function saveJob(CreateJob $create): void
    {
        $companyId = CurrentCompany::id();

        $validated = $this->validate([
            'formCustomerId' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'formRouteId' => ['required', 'integer', Rule::exists('routes', 'id')->where('company_id', $companyId)],
            'formCommodity' => ['nullable', 'string', 'max:120'],
            'formTonnes' => ['nullable', 'numeric', 'gte:0', 'lt:100000'],
            'formLoadOn' => ['nullable', 'date'],
            'formDeliveryOn' => ['nullable', 'date', 'after_or_equal:formLoadOn'],
            'formNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Editing is only ever a correction of an enquiry: once a job is priced, its terms are an offer
        // and §10.3 changes them by re-quoting, not by rewriting the row.
        $existing = $this->editingId === null ? null : Job::query()->findOrFail($this->editingId);

        if ($existing !== null && $existing->status !== JobStatus::Enquiry) {
            $this->addError('form', __('Only an enquiry may be corrected — re-quote it instead of rewriting an offer.'));

            return;
        }

        abort_unless(Gate::allows($existing === null ? 'create' : 'update', $existing ?? Job::class), 403);

        try {
            $job = $create(new JobDto(
                customerId: (int) $validated['formCustomerId'],
                routeId: (int) $validated['formRouteId'],
                commodity: $validated['formCommodity'] ?? null,
                tonnes: isset($validated['formTonnes']) && $validated['formTonnes'] !== '' ? (float) $validated['formTonnes'] : null,
                requiredLoadOn: $validated['formLoadOn'] ?? null,
                requiredDeliveryOn: $validated['formDeliveryOn'] ?? null,
                notes: $validated['formNotes'] ?? null,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->reset(['editingId', 'formCustomerId', 'formRouteId', 'formCommodity', 'formTonnes', 'formLoadOn', 'formDeliveryOn', 'formNotes']);
        unset($this->jobs, $this->customerChoices, $this->routeChoices);
        $this->dispatch('close-job-form');
        $this->dispatch('toast', message: __(':job opened as an enquiry.', ['job' => $job->job_no]), kind: 'success');
    }

    /* ------------------------------------------------------------------ quote */

    public function openQuote(string $jobId): void
    {
        $job = Job::query()->findOrFail($jobId);

        abort_unless(Gate::allows('quote', $job), 403);

        $this->reset(['quoteJobId', 'quoteAmount', 'quoteRate', 'decisionJobId']);
        $this->quoteJobId = $job->id;
        $this->quoteRateType = ($job->rate_type ?? RouteRateType::PerTrip)->value;
        $this->quoteCurrency = $job->currency_code ?? $this->companyCurrency();
        $this->formDatesFor($job);
        $this->resetValidation();

        $this->dispatch('open-job-quote');
    }

    public function saveQuote(QuoteJob $quote): void
    {
        $job = Job::query()->find($this->quoteJobId);

        if ($job === null) {
            $this->addError('quote', __('That job is no longer here. Reopen it from the board.'));

            return;
        }

        abort_unless(Gate::allows('quote', $job), 403);

        $validated = $this->validate([
            'quoteRateType' => ['required', 'string', Rule::in(RouteRateType::values())],
            'quoteAmount' => ['required', 'string'],
            'quoteCurrency' => ['required', 'string', 'size:3', Rule::in(array_keys($this->currencies()))],
            'quoteRate' => ['nullable', 'string'],
            'quoteRateDate' => ['nullable', 'date'],
            // The quote carries the dates it was priced for, so the form that sets the price answers for
            // them too: they are on screen and editable here rather than written from a field nobody sees.
            'formLoadOn' => ['nullable', 'date'],
            'formDeliveryOn' => ['nullable', 'date', 'after_or_equal:formLoadOn'],
        ]);

        try {
            $quoted = $quote($job, new JobQuoteDto(
                rateType: RouteRateType::from($validated['quoteRateType']),
                amountMajor: $validated['quoteAmount'],
                currencyCode: $validated['quoteCurrency'],
                rate: $this->quoteRate === '' ? null : $this->quoteRate,
                rateDate: $this->quoteRateDate === '' ? null : $this->quoteRateDate,
                requiredLoadOn: $this->formLoadOn === '' ? null : $this->formLoadOn,
                requiredDeliveryOn: $this->formDeliveryOn === '' ? null : $this->formDeliveryOn,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('quote', $refused->getMessage());

            return;
        }

        $this->reset(['quoteJobId', 'quoteAmount', 'quoteRate']);
        unset($this->jobs);
        $this->dispatch('close-job-quote');
        $this->dispatch('toast', message: __(':job is quoted — waiting for the customer to say yes.', ['job' => $quoted->job_no]), kind: 'success');
    }

    /* --------------------------------------------------------------- decisions */

    public function openDecision(string $jobId, string $kind): void
    {
        $job = Job::query()->findOrFail($jobId);

        abort_unless(Gate::allows($kind, $job), 403);

        $this->reset(['decisionJobId', 'decisionReason', 'quoteJobId']);
        $this->decisionJobId = $job->id;
        $this->decisionKind = $kind;
        $this->resetValidation();

        $this->dispatch('open-job-decision');
    }

    /**
     * The three hand-set ends of §10.3, through one method.
     *
     * They share a control because they share a shape — a named job, an optional reason, an Action that
     * refuses — and because `accept` is the one decision with no reason attached, the validation is the
     * only difference between them. Three near-identical methods would be three places for that rule to
     * disagree with itself.
     */
    public function commitDecision(AcceptJob $accept, DeclineJob $decline, CloseJob $close): void
    {
        $job = Job::query()->find($this->decisionJobId);

        if ($job === null) {
            $this->addError('decision', __('That job is no longer here. Reopen it from the board.'));

            return;
        }

        abort_unless(Gate::allows($this->decisionKind, $job), 403);

        if ($this->decisionKind !== 'accept') {
            $this->validate(['decisionReason' => ['required', 'string', 'max:255']]);
        }

        try {
            $moved = match ($this->decisionKind) {
                'accept' => $accept($job, (int) auth()->id()),
                'decline' => $decline($job, $this->decisionReason, (int) auth()->id()),
                'close' => $close($job, $this->decisionReason, (int) auth()->id()),
                default => throw new InvalidArgumentException('This decision is not one the register knows how to record. Close the dialog and choose the job again.'),
            };
        } catch (Throwable $refused) {
            $this->addError('decision', $refused->getMessage());

            return;
        }

        $this->reset(['decisionJobId', 'decisionKind', 'decisionReason']);
        unset($this->jobs);
        $this->dispatch('close-job-decision');
        $this->dispatch('toast', message: $moved->status === JobStatus::Accepted
            // The register cannot hand the row to the board, so the answer says where to go next.
            ? __(':job is accepted — now put a truck on it with "Plan a trip".', ['job' => $moved->job_no])
            : __(':job is :status.', ['job' => $moved->job_no, 'status' => $moved->status->label()]), kind: 'success');
    }

    /* ------------------------------------------------------------------ reads */

    /** @return LengthAwarePaginator<int, Job> */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $status = $this->statusFilter;
        $needs = $this->needs;
        $direction = $this->appliedDirection();

        $query = Job::query()
            ->with(['customer:id,name,code', 'route:id,code,origin,destination', 'trips'])
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->when($needs === 'quote', static fn ($q) => $q->where('status', JobStatus::Enquiry->value))
            ->when($needs === 'answer', static fn ($q) => $q->where('status', JobStatus::Quoted->value))
            ->when($needs === 'trip', static fn ($q) => $q
                ->whereIn('status', [JobStatus::Accepted->value, JobStatus::InExecution->value])
                ->whereDoesntHave('trips', static fn ($t) => $t->whereNotIn('status', [
                    TripStatus::Completed->value,
                    TripStatus::Closed->value,
                    TripStatus::Cancelled->value,
                    TripStatus::Aborted->value,
                ])))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(static function ($inner) use ($search): void {
                    $inner->where('job_no', 'like', "%{$search}%")
                        ->orWhere('commodity', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('customer', static fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            });

        // The untouched register is a queue, not an archive: a job with a load date is a job somebody is
        // waiting for, and everything without one is backlog. Sorting replaces that reading, so the
        // dispatcher who wants "biggest quote first" gets it instead of a fixed order with one hope.
        return (match ($this->sort) {
            'job_no' => $query->orderBy('job_no', $direction),
            'load_on' => $query->orderByRaw("required_load_on is null, required_load_on {$direction}"),
            'status' => $query->orderBy('status', $direction),
            'quote' => $query->orderBy('base_amount', $direction),
            default => $query->orderByRaw('required_load_on is null, required_load_on asc, created_at desc'),
        })
            ->paginate(15)
            ->withQueryString();
    }

    /** @return list<array{value: string, label: string}> */
    #[Computed]
    public function statusChoices(): array
    {
        return array_map(
            static fn (JobStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            JobStatus::cases(),
        );
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function customerChoices(): array
    {
        return Customer::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (Customer $c): array => ['id' => $c->id, 'label' => $c->name.($c->code === null ? '' : ' · '.$c->code)])
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function routeChoices(): array
    {
        return Route::query()
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn (Route $r): array => ['id' => $r->id, 'label' => $r->code.' · '.$r->origin.' → '.$r->destination])
            ->all();
    }

    /** §10.3's derived badges for one row, from the trips already loaded on the relation. */
    public function progress(Job $job): JobProgress
    {
        return new JobProgress($job, $job->trips);
    }

    /** The currency select, in the company's own ordering. */
    #[Computed]
    public function currencies(): array
    {
        return Currency::active();
    }

    /** A foreign quote needs the rate it was converted at (§5.3); a base one does not. */
    public function quoteNeedsRate(): bool
    {
        return $this->quoteCurrency !== '' && $this->quoteCurrency !== $this->companyCurrency();
    }

    public function decisionTitle(): string
    {
        return match ($this->decisionKind) {
            'accept' => __('Accept this job'),
            'decline' => __('Decline this job'),
            'close' => __('Close this job'),
            default => '',
        };
    }

    public function decisionWantsReason(): bool
    {
        return $this->decisionKind !== 'accept' && $this->decisionKind !== '';
    }

    private function formDatesFor(Job $job): void
    {
        $this->formLoadOn = $job->required_load_on?->toDateString() ?? '';
        $this->formDeliveryOn = $job->required_delivery_on?->toDateString() ?? '';
    }

    private function companyCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    private function today(): Carbon
    {
        return Carbon::today(CurrentCompany::timezone());
    }
}; ?>

<div class="grid gap-6">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Jobs') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Freight work, from enquiry to close. Quotes show only to the seats allowed to read them.') }}
            </x-ui.text>
        </div>

        @can('create', App\Domain\Operations\Models\Job::class)
            <x-ui.button wire:click="openCreate">{{ __('New job') }}</x-ui.button>
        @endcan
    </header>

    {{-- The worklist, before the register: three questions the desk is asked every morning, each with the
         count already on it so nobody has to click to find out how much they owe. --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-fg-ink-muted">{{ __('Waiting on us') }}</span>

        @foreach ($this->needsOptions as $kind => $label)
            <x-ui.button
                size="sm"
                :variant="$needs === $kind ? 'primary' : 'outline'"
                wire:click="$set('needs', '{{ $needs === $kind ? '' : $kind }}')"
                aria-pressed="{{ $needs === $kind ? 'true' : 'false' }}"
            >
                {{ $label }}
                <span class="fg-numeric text-xs opacity-70">{{ $this->needsCounts[$kind] }}</span>
            </x-ui.button>
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <x-ui.input name="search" wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Job reference, commodity, customer…') }}" />

        <div class="grid gap-1">
            <label for="job-status" class="text-sm text-fg-ink">{{ __('Status') }}</label>
            <x-ui.select name="statusFilter"
                id="job-status"
                wire:model.live="statusFilter"
            >
                <option value="">{{ __('Any status') }}</option>
                @foreach ($this->statusChoices as $choice)
                    <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($this->hasFilters())
            <x-ui.button type="button" size="sm" variant="ghost" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
        @endif
    </div>

    <x-ui.table :caption="__('The freight job register — every job, its corridor, and the state it has reached')">
        <x-slot name="head">
            <x-ui.table.th sortable :direction="$this->sortDirection('job_no')" wire:click="sortBy('job_no')">
                {{ __('Job') }}
            </x-ui.table.th>
            <x-ui.table.th>{{ __('Customer · corridor') }}</x-ui.table.th>
            <x-ui.table.th sortable :direction="$this->sortDirection('load_on')" wire:click="sortBy('load_on')">
                {{ __('Load on') }}
            </x-ui.table.th>
            <x-ui.table.th sortable :direction="$this->sortDirection('status')" wire:click="sortBy('status')">
                {{ __('Status') }}
            </x-ui.table.th>
            <x-ui.table.th numeric sortable :direction="$this->sortDirection('quote')" wire:click="sortBy('quote')">
                {{ __('Quoted') }}
            </x-ui.table.th>
            <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
        </x-slot>

        @forelse ($this->jobs as $job)
            @php($badges = $this->progress($job)->badges())

            <tr wire:key="job-{{ $job->id }}">
                <x-ui.table.td nowrap>
                    <span class="fg-identifier text-sm text-fg-ink">{{ $job->job_no }}</span>
                    <span class="block text-xs text-fg-ink-secondary">{{ $job->commodity ?? __('(no commodity)') }}</span>
                    @if ($job->tonnes_10_2 !== null)
                        <span class="block text-xs text-fg-ink-muted">{{ __(':tonnes t', ['tonnes' => rtrim(rtrim((string) $job->tonnes_10_2, '0'), '.')]) }}</span>
                    @endif
                </x-ui.table.td>

                <x-ui.table.td>
                    <span class="block text-fg-ink">{{ $job->customer?->name ?? __('Customer #:id', ['id' => $job->customer_id]) }}</span>
                    <span class="block text-xs text-fg-ink-muted">
                        {{ $job->route?->code ?? __('(no corridor)') }}{{ $job->route?->origin === null ? '' : ' · '.$job->route->origin.' → '.$job->route->destination }}
                    </span>
                </x-ui.table.td>

                <x-ui.table.td nowrap>
                    <span class="text-fg-ink">{{ $job->required_load_on?->format('d M Y') ?? __('unscheduled') }}</span>
                    @if ($job->required_delivery_on !== null)
                        <span class="block text-xs text-fg-ink-muted">{{ __('deliver by :date', ['date' => $job->required_delivery_on->format('d M Y')]) }}</span>
                    @endif
                </x-ui.table.td>

                <x-ui.table.td>
                    <x-ui.badge :tone="$job->status->tone()">{{ $job->status->label() }}</x-ui.badge>

                    @if ($badges !== [])
                        <span class="mt-1 flex flex-wrap gap-1">
                            @foreach ($badges as $badge)
                                {{-- §10.3: these are read from the job's trips on every render, never stored. --}}
                                <x-ui.badge :tone="$badge['tone']" size="xs">{{ __($badge['label']) }}</x-ui.badge>
                            @endforeach
                        </span>
                    @endif
                </x-ui.table.td>

                <x-ui.table.td numeric nowrap>
                    {{-- §9.3 gives this column to whoever may read the register: the Dispatcher *writes* it,
                         and a job whose own price is hidden from the person quoting it would have to be typed
                         blind. What stays hidden from that seat is the rate *versus* cost — `jobs.rate_cost.view`,
                         which is the margin, and is a trip number rather than a job one. --}}
                    <span class="text-fg-ink">{{ $job->quoteLabel() }}</span>
                    @if ($job->quoted_on !== null)
                        <span class="block text-xs text-fg-ink-muted">{{ $job->quoted_on->format('d M Y') }}</span>
                    @endif
                </x-ui.table.td>

                <x-ui.table.td numeric nowrap>
                    {{-- The spine's one broken link, closed here: an accepted job used to offer nothing, so
                         the trip that follows it depended on the dispatcher knowing to open the board and
                         find the same job again in a dropdown. --}}
                    @can('trips.plan')
                        @if (in_array($job->status, [App\Domain\Operations\JobStatus::Accepted, App\Domain\Operations\JobStatus::InExecution], true))
                            <x-ui.link wire:navigate :href="$this->planTripUrl($job)" class="mr-2">{{ __('Plan a trip') }}</x-ui.link>
                        @endif
                    @endcan

                    @can('quote', $job)
                        <x-ui.button size="sm" variant="ghost" wire:click="openQuote('{{ $job->id }}')">{{ $job->currency_code === null ? __('Quote') : __('Re-quote') }}</x-ui.button>
                    @endcan

                    @can('accept', $job)
                        <x-ui.button size="sm" variant="ghost" wire:click="openDecision('{{ $job->id }}', 'accept')">{{ __('Accept') }}</x-ui.button>
                    @endcan

                    @can('decline', $job)
                        <x-ui.button size="sm" variant="ghost" wire:click="openDecision('{{ $job->id }}', 'decline')">{{ __('Decline') }}</x-ui.button>
                    @endcan

                    @can('update', $job)
                        @if ($job->status === App\Domain\Operations\JobStatus::Enquiry)
                            <x-ui.button size="sm" variant="ghost" wire:click="edit('{{ $job->id }}')">{{ __('Correct') }}</x-ui.button>
                        @endif
                    @endcan

                    @can('close', $job)
                        <x-ui.button size="sm" variant="ghost" wire:click="openDecision('{{ $job->id }}', 'close')">{{ __('Close') }}</x-ui.button>
                    @endcan

                    {{-- A row with no buttons is a row a seat cannot read the reason for; §9.3's seats are
                         allowed to open this register, so the silence was the only answer they got. --}}
                    @if ($job->status->isTerminal())
                        <span class="text-xs text-fg-ink-muted">{{ __('Finished — nothing to do here') }}</span>
                    @elseif (! (Gate::any(['quote', 'accept', 'decline', 'close'], $job) || Gate::allows('trips.plan')))
                        <span class="text-xs text-fg-ink-muted">{{ __('This seat reads the register only') }}</span>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty-state
                        icon="clipboard-document-list"
                        title="{{ $this->hasFilters() ? __('Nothing answers that filter') : __('No jobs yet') }}"
                        description="{{ $this->hasFilters() ? __('The register is empty with these filters on — that is usually the filter, not the week.') : __('Freight work starts here: open an enquiry, price the corridor, accept it.') }}"
                    >
                        @if ($this->hasFilters())
                            <x-ui.button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        @elseif (Gate::allows('create', App\Domain\Operations\Models\Job::class))
                            <x-ui.button size="sm" wire:click="openCreate">{{ __('Open the first enquiry') }}</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <div>
        {{ $this->jobs->links() }}
    </div>

    {{-- The enquiry form: who, where, what, by when. No price — that is the next decision. --}}
    <x-ui.dialog name="job-form" :title="$editingId === null ? __('New job') : __('Correct this enquiry')" x-on:dialog-closed="$wire.call('clearJobForm')">
        <form wire:submit="saveJob" class="grid gap-4 p-5">
            <div class="grid gap-1">
                <label for="form-customer" class="text-sm text-fg-ink">{{ __('Customer') }}</label>
                <x-ui.select name="formCustomerId" id="form-customer" wire:model="formCustomerId">
                    <option value="">{{ __('Choose a customer…') }}</option>
                    @foreach ($this->customerChoices as $customer)
                        <option value="{{ $customer['id'] }}">{{ $customer['label'] }}</option>
                    @endforeach
                </x-ui.select>
                @if ($this->customerChoices === [])
                    <p class="text-xs text-fg-ink-muted">
                        {{ __('No active customer to choose. Add one to the customer register first.') }}
                        @can('viewAny', App\Domain\Invoicing\Models\Customer::class)
                            <x-ui.link :href="route('invoicing.customers')" wire:navigate>{{ __('Open customers') }}</x-ui.link>
                        @endcan
                    </p>
                @endif
            </div>

            <div class="grid gap-1">
                <label for="form-route" class="text-sm text-fg-ink">{{ __('Corridor') }}</label>
                <x-ui.select name="formRouteId" id="form-route" wire:model="formRouteId">
                    <option value="">{{ __('Choose a route…') }}</option>
                    @foreach ($this->routeChoices as $route)
                        <option value="{{ $route['id'] }}">{{ $route['label'] }}</option>
                    @endforeach
                </x-ui.select>
                @if ($this->routeChoices === [])
                    <p class="text-xs text-fg-ink-muted">
                        {{ __('No active corridor to choose. Add the lane to the route register first.') }}
                        @can('viewAny', App\Domain\Operations\Models\Route::class)
                            <x-ui.link :href="route('operations.routes')" wire:navigate>{{ __('Open routes') }}</x-ui.link>
                        @endcan
                    </p>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.input name="formCommodity" wire:model="formCommodity" :label="__('Commodity')" />
                <x-ui.input name="formTonnes" wire:model="formTonnes" :label="__('Tonnes')" type="number" step="0.01" />
                <x-ui.input name="formLoadOn" wire:model="formLoadOn" :label="__('Must load on')" type="date" />
                <x-ui.input name="formDeliveryOn" wire:model="formDeliveryOn" :label="__('Must deliver by')" type="date" />
            </div>

            <p class="text-xs text-fg-ink-muted">{{ __('Only the customer and the corridor are needed to open an enquiry. The rest can wait until the price does.') }}</p>

            <x-ui.input name="formNotes" wire:model="formNotes" :label="__('Notes')" />

            @error('form')
                <x-ui.alert tone="danger" :title="__('We could not open that job')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveJob">
                    <span wire:loading.remove wire:target="saveJob">{{ $editingId === null ? __('Open the enquiry') : __('Save the correction') }}</span>
                    <span wire:loading wire:target="saveJob">{{ $editingId === null ? __('Opening…') : __('Saving…') }}</span>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-form')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- The quote: §5's money, with the conversion rate the books will read back. --}}
    <x-ui.dialog name="job-quote" :title="__('Quote this job')" x-on:dialog-closed="$wire.call('clearQuoteForm')">
        <form wire:submit="saveQuote" class="grid gap-4 p-5">
            <p class="text-sm text-fg-ink-secondary">
                {{ __('A price turns an enquiry into an offer. Nothing can be dispatched against a job until the customer accepts it.') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <label for="quote-type" class="text-sm text-fg-ink">{{ __('Rate type') }}</label>
                    <x-ui.select name="quoteRateType" id="quote-type" wire:model="quoteRateType">
                        @foreach (App\Domain\Operations\RouteRateType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1">
                    <label for="quote-currency" class="text-sm text-fg-ink">{{ __('Currency') }}</label>
                    <x-ui.select name="quoteCurrency" id="quote-currency" wire:model="quoteCurrency">
                        @foreach ($this->currencies as $currency)
                            <option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input name="quoteAmount" wire:model="quoteAmount" :label="__('Amount')" type="text" inputmode="decimal" />

                @if ($this->quoteNeedsRate())
                    <x-ui.input name="quoteRate" wire:model="quoteRate" :label="__('Rate to base (:code)', ['code' => $this->companyCurrency()])" type="text" inputmode="decimal" />
                    <x-ui.input name="quoteRateDate" wire:model="quoteRateDate" :label="__('Rate dated')" type="date" />
                @endif

                {{-- The quote carries the dates it was priced for. They were written silently from the
                     enquiry before; a price agreed against a delivery date nobody re-read is a promise
                     with two versions. --}}
                <x-ui.input name="formLoadOn" wire:model="formLoadOn" :label="__('Load on')" type="date" />
                <x-ui.input name="formDeliveryOn" wire:model="formDeliveryOn" :label="__('Deliver by')" type="date" />
            </div>

            <p class="text-xs text-fg-ink-muted">
                {{ __('A foreign price is converted once, at the rate you type here, and the base figure is never worked out again — so the books still agree after the rate moves.') }}
            </p>

            @error('quote')
                <x-ui.alert tone="danger" :title="__('We could not price this job')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveQuote">
                    <span wire:loading.remove wire:target="saveQuote">{{ __('Put a price on it') }}</span>
                    <span wire:loading wire:target="saveQuote">{{ __('Pricing…') }}</span>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-quote')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- Accept / decline / close: the three states a person sets, with the reason §10.2 insists on. --}}
    <x-ui.dialog name="job-decision" :title="$this->decisionTitle()" x-on:dialog-closed="$wire.call('clearDecisionForm')">
        <form wire:submit="commitDecision" class="grid gap-4 p-5">
            @if ($this->decisionWantsReason())
                <x-ui.input
                    name="decisionReason"
                    wire:model="decisionReason"
                    :label="$this->decisionKind === 'close' ? __('Why is this job closed? It stays on the job’s trail.') : __('Reason — kept on the job’s trail.')"
                />
            @else
                <p class="text-sm text-fg-ink-secondary">
                    {{ __('Accepting commits this price to the customer and lets trucks run against it. The next step is a trip on the dispatch board.') }}
                </p>
            @endif

            @error('decision')
                <x-ui.alert tone="danger" :title="__('We could not record that')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="commitDecision">
                    <span wire:loading.remove wire:target="commitDecision">{{ $this->decisionTitle() }}</span>
                    <span wire:loading wire:target="commitDecision">{{ __('Working…') }}</span>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-decision')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
