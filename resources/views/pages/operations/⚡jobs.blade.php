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
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->jobs);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->jobs);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    /* ---------------------------------------------------------------- create */

    public function openCreate(): void
    {
        $this->reset(['editingId', 'formCustomerId', 'formRouteId', 'formCommodity', 'formTonnes', 'formLoadOn', 'formDeliveryOn', 'formNotes']);
        $this->resetValidation();
        $this->dispatch('open-job-form');
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
                default => throw new InvalidArgumentException('Unknown job decision.'),
            };
        } catch (Throwable $refused) {
            $this->addError('decision', $refused->getMessage());

            return;
        }

        $this->reset(['decisionJobId', 'decisionKind', 'decisionReason']);
        unset($this->jobs);
        $this->dispatch('close-job-decision');
        $this->dispatch('toast', message: __(':job is :status.', ['job' => $moved->job_no, 'status' => $moved->status->label()]), kind: 'success');
    }

    /* ------------------------------------------------------------------ reads */

    /** @return LengthAwarePaginator<int, Job> */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $status = $this->statusFilter;

        return Job::query()
            ->with(['customer:id,name,code', 'route:id,code,origin,destination', 'trips'])
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(static function ($inner) use ($search): void {
                    $inner->where('job_no', 'like', "%{$search}%")
                        ->orWhere('commodity', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('customer', static fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            // A job with a load date is a job somebody is waiting for; everything else is backlog.
            ->orderByRaw('required_load_on is null, required_load_on asc, created_at desc')
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
                {{ __('Freight work, from enquiry to closed. Prices appear only for the seats §9.3 says may read them.') }}
            </x-ui.text>
        </div>

        @can('create', App\Domain\Operations\Models\Job::class)
            <x-ui.button wire:click="openCreate">{{ __('New job') }}</x-ui.button>
        @endcan
    </header>

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
            <x-ui.button type="button" size="sm" variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</x-ui.button>
        @endif
    </div>

    <x-ui.table>
        <x-slot name="head">
            <x-ui.table.th>{{ __('Job') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Customer · corridor') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Load on') }}</x-ui.table.th>
            <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
            <x-ui.table.th numeric>{{ __('Quoted') }}</x-ui.table.th>
            <x-ui.table.th class="text-right">{{ __('Actions') }}</x-ui.table.th>
        </x-slot>

        @forelse ($this->jobs as $job)
            @php($badges = $this->progress($job)->badges())

            <tr wire:key="job-{{ $job->id }}">
                <x-ui.table.td nowrap>
                    <span class="font-mono text-sm text-fg-ink">{{ $job->job_no }}</span>
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

                <x-ui.table.td class="text-right whitespace-nowrap">
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
                </x-ui.table.td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty-state
                        icon="clipboard-document-list"
                        title="{{ $this->hasFilters() ? __('No jobs match') : __('No jobs yet') }}"
                        description="{{ $this->hasFilters() ? __('Nothing on the register answers that search or status.') : __('Freight work starts here: open an enquiry, price the corridor, accept it.') }}"
                    />
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <div>
        {{ $this->jobs->links() }}
    </div>

    {{-- The enquiry form: who, where, what, by when. No price — that is the next decision. --}}
    <x-ui.dialog name="job-form" :title="$editingId === null ? __('New job') : __('Correct this enquiry')">
        <form wire:submit="saveJob" class="grid gap-4 p-5">
            <div class="grid gap-1">
                <label for="form-customer" class="text-sm text-fg-ink">{{ __('Customer') }}</label>
                <x-ui.select name="formCustomerId" id="form-customer" wire:model="formCustomerId">
                    <option value="">{{ __('Choose a customer…') }}</option>
                    @foreach ($this->customerChoices as $customer)
                        <option value="{{ $customer['id'] }}">{{ $customer['label'] }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-1">
                <label for="form-route" class="text-sm text-fg-ink">{{ __('Corridor') }}</label>
                <x-ui.select name="formRouteId" id="form-route" wire:model="formRouteId">
                    <option value="">{{ __('Choose a route…') }}</option>
                    @foreach ($this->routeChoices as $route)
                        <option value="{{ $route['id'] }}">{{ $route['label'] }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.input name="formCommodity" wire:model="formCommodity" :label="__('Commodity')" />
                <x-ui.input name="formTonnes" wire:model="formTonnes" :label="__('Tonnes')" type="number" step="0.01" />
                <x-ui.input name="formLoadOn" wire:model="formLoadOn" :label="__('Must load on')" type="date" />
                <x-ui.input name="formDeliveryOn" wire:model="formDeliveryOn" :label="__('Must deliver by')" type="date" />
            </div>

            <x-ui.input name="formNotes" wire:model="formNotes" :label="__('Notes')" />

            @error('form')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit">{{ $editingId === null ? __('Open the enquiry') : __('Save the correction') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-form')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- The quote: §5's money, with the conversion rate the books will read back. --}}
    <x-ui.dialog name="job-quote" :title="__('Quote this job')">
        <form wire:submit="saveQuote" class="grid gap-4 p-5">
            <p class="text-sm text-fg-ink-secondary">
                {{ __('A price turns an enquiry into an offer. Nothing can be dispatched against a job until it is accepted (§4.1).') }}
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
            </div>

            <p class="text-xs text-fg-ink-muted">
                {{ __('The base figure is written at the rate you state and never re-derived — §5.3, so the books keep agreeing after the FX moves.') }}
            </p>

            @error('quote')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit">{{ __('Put a price on it') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-quote')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- Accept / decline / close: the three states a person sets, with the reason §10.2 insists on. --}}
    <x-ui.dialog name="job-decision" :title="$this->decisionTitle()">
        <form wire:submit="commitDecision" class="grid gap-4 p-5">
            @if ($this->decisionWantsReason())
                <x-ui.input
                    name="decisionReason"
                    wire:model="decisionReason"
                    :label="$this->decisionKind === 'close' ? __('Why is this job closed? (§10.3 — the only hand-set end)') : __('Why? (kept on the trail, §4.4)')"
                />
            @else
                <p class="text-sm text-fg-ink-secondary">
                    {{ __('Accepting commits the price to the customer and lets trips run against it (§4.1).') }}
                </p>
            @endif

            @error('decision')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit">{{ $this->decisionTitle() }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-job-decision')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
