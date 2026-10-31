<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Pages;

use App\Domain\Accounting\Actions\RecordBill;
use App\Domain\Accounting\BillCategory;
use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Data\BillLineInput;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fuel\Queries\UnbilledDeliveries;
use App\Domain\Invoicing\Queries\EffectiveVat;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Queries\UnbilledReceipts;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The bills register (§3.2, §6.8): what the suppliers have billed us, and what we still owe them.
 *
 * The record panel sits on the register because entering a paper document is one decision, the same
 * reason the consolidated run lives on the invoice list (§20.34). VAT is previewed per line from the
 * rate effective on the bill date — the figure the Action will compute is shown before it is spent,
 * so a clerk never discovers the gross total after pressing record (§5.5).
 *
 * Fuel and parts lines must name the leg their account demands (§6.1's masks: 5100 truck+trip,
 * 5310/5300 truck); the row's pickers appear with the category that needs them, and the Action
 * refuses by name if a screen ever posts without one.
 *
 * Slice 5b's second shape is asked up front: **is this paper a new cost, or the receipt for something
 * the ledger already owes?** A bulk delivery or a parts receipt posted `Cr 2110` at the movement
 * (§20.26/§20.29), so its bill documents that figure and posts nothing twice — which is why choosing
 * the link hides the lines and shows the obligation's own number instead of letting anyone type one.
 *
 * The register's money column is the **balance**, not the gross: what the fleet still owes is the
 * question a payables screen answers, and it is derived from the payment rows (§10.4's rule, sideways).
 *
 * Only `accounting.view` is asked for the list; the panel is gated by `bills.create` and the Action
 * asks again, because a screen is not a permission check (§9.2).
 */
new #[Title('Bills')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('state')]
    public string $stateFilter = '';

    public string $supplierId = '';

    public string $supplierDocNo = '';

    /** 'lines' or a {@see BillLinkedSource} value — the two shapes a supplier paper can take (§20.36). */
    public string $shape = 'lines';

    public string $obligationId = '';

    public string $billOn = '';

    public string $dueOn = '';

    public string $note = '';

    /** @var list<array{description: string, category: string, amount: string, truck: string, trip: string}> */
    public array $rows = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->billOn = Carbon::now()->toDateString();
        $this->dueOn = Carbon::now()->toDateString();
        $this->addRow();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stateFilter'], true)) {
            $this->resetPage();
            unset($this->bills, $this->outstandingMinor);
        }

        if ($property === 'supplierId') {
            $this->applyTerms();
        }

        if ($property === 'shape') {
            // A different shape is a different question, so the previous answer cannot carry over — and
            // the obligation list is derived from the shape, so its cache goes with it.
            $this->obligationId = '';
            unset($this->obligations, $this->chosenObligation);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'stateFilter']);
        $this->resetPage();
        unset($this->bills, $this->outstandingMinor);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->stateFilter !== '';
    }

    /** @return array{description: string, category: string, amount: string, truck: string, trip: string} */
    private function blankRow(): array
    {
        return ['description' => '', 'category' => '', 'amount' => '', 'truck' => '', 'trip' => ''];
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    /**
     * @return list<array{code: string, label: string, truck: bool, trip: bool}>
     */
    #[Computed]
    public function categories(): array
    {
        $codes = [];

        foreach (BillCategory::cases() as $category) {
            $codes[$category->accountCode()][] = $category;
        }

        $required = DB::table('gl_accounts')
            ->whereIn('code', array_keys($codes))
            ->pluck('allowed_dimension_mask', 'code');

        $out = [];

        foreach (BillCategory::cases() as $category) {
            $mask = (int) ($required[$category->accountCode()] ?? 0);

            $out[] = [
                'code' => $category->value,
                'label' => $category->label(),
                'truck' => ($mask & 1) === 1,
                'trip' => ($mask & 2) === 2,
            ];
        }

        return $out;
    }

    /** @return array<string, array{code: string, label: string, truck: bool, trip: bool}> */
    #[Computed]
    public function categoryByCode(): array
    {
        return collect($this->categories)->keyBy('code')->all();
    }

    #[Computed]
    public function suppliers(): \Illuminate\Support\Collection
    {
        return Supplier::query()->where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function trucks(): \Illuminate\Support\Collection
    {
        return DB::table('trucks')
            ->where('company_id', CurrentCompany::id())
            ->orderBy('registration_no')
            ->pluck('registration_no', 'id');
    }

    #[Computed]
    public function trips(): \Illuminate\Support\Collection
    {
        return DB::table('trips')
            ->where('company_id', CurrentCompany::id())
            ->orderByDesc('trip_no')
            ->limit(200)
            ->pluck('trip_no', 'id');
    }

    /**
     * The obligations the chosen shape can document, read through each module's own door (§3.1 D5):
     * a bulk delivery that posted `Cr 2110` (§20.26) or a parts receipt that funded the shelf (§20.29),
     * both still waiting for the supplier's paper.
     *
     * @return list<\App\Domain\Accounting\Data\UnbilledObligation>
     */
    #[Computed]
    public function obligations(): array
    {
        return match ($this->shape) {
            BillLinkedSource::FuelDelivery->value => app(UnbilledDeliveries::class)->all(),
            BillLinkedSource::StockReceipt->value => app(UnbilledReceipts::class)->all(),
            default => [],
        };
    }

    /** The figure the linked bill must carry: the book's, not a typed one (§20.36). */
    #[Computed]
    public function chosenObligation(): ?\App\Domain\Accounting\Data\UnbilledObligation
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->id === $this->obligationId) {
                return $obligation;
            }
        }

        return null;
    }

    /**
     * The VAT the Action will apply, previewed line by line (§5.5: per line, rounded once). A missing
     * rate register answers null — the panel says so rather than quietly previewing zero VAT.
     */
    #[Computed]
    public function vatPreview(): ?array
    {
        $rate = app(EffectiveVat::class)->on(Carbon::parse($this->billOn));

        if ($rate === null) {
            return null;
        }

        $base = CurrentCompany::model()->base_currency_code;
        $perRow = [];
        $total = 0;

        foreach ($this->rows as $index => $row) {
            $amount = trim($row['amount'] ?? '');

            if ($amount === '' || ! is_numeric($amount)) {
                $perRow[$index] = ['tax' => 0, 'net' => 0];

                continue;
            }

            try {
                $net = Money::ofMajor($amount, $base)->minorUnits;
            } catch (\DomainException) {
                $perRow[$index] = ['tax' => 0, 'net' => 0];

                continue;
            }

            $tax = $rate->taxOn($net);
            $perRow[$index] = ['tax' => $tax, 'net' => $net];
            $total += $tax;
        }

        return ['rows' => $perRow, 'total' => $total, 'label' => $rate->label];
    }

    /**
     * @return array{net: int, tax: int, gross: int}
     */
    #[Computed]
    public function enteredTotals(): array
    {
        $base = CurrentCompany::model()->base_currency_code;
        $net = 0;

        foreach ($this->rows as $row) {
            $amount = trim($row['amount'] ?? '');

            if ($amount === '' || ! is_numeric($amount)) {
                continue;
            }

            try {
                $net += Money::ofMajor($amount, $base)->minorUnits;
            } catch (\DomainException) {
                // Mid-typing figures are not in the running total; the parse itself will refuse at
                // submit time with a message naming the line.
                continue;
            }
        }

        $tax = $this->vatPreview()['total'] ?? 0;

        return ['net' => $net, 'tax' => $tax, 'gross' => $net + $tax];
    }

    /**
     * One row per document, newest first. A paid bill stays in the register — the list is the record of
     * what arrived, not a work queue — and its state badge is **derived** from the payment rows the way
     * an invoice's is (§10.4, copied sideways by §20.36), so nothing here can read paid while still owed.
     */
    #[Computed]
    public function bills(): LengthAwarePaginator
    {
        return Bill::query()
            ->with('supplier')
            ->when($this->search !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('number', 'like', $like)
                    ->orWhere('supplier_doc_no', 'like', $like)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like)));
            })
            ->when($this->stateFilter === 'open', fn ($query) => $query
                ->where('status', BillLifecycle::Recorded->value)
                ->where('balance_amount', '>', 0))
            ->when($this->stateFilter === 'paid', fn ($query) => $query
                ->where('status', BillLifecycle::Recorded->value)
                ->where('balance_amount', 0))
            ->when($this->stateFilter === 'void', fn ($query) => $query->where('status', BillLifecycle::Void->value))
            ->when($this->stateFilter === 'draft', fn ($query) => $query->where('status', BillLifecycle::Draft->value))
            ->orderByDesc('bill_on')
            ->orderByDesc('number')
            ->paginate(25);
    }

    /**
     * The payable the register's footer states: what is **still owed**, not what was billed — the
     * payments have already reduced it, so this is the figure the ledger's `2110` credit should agree
     * with for the same set (§6.9's tie-out asks the same question from the other side).
     */
    #[Computed]
    public function outstandingMinor(): int
    {
        return (int) Bill::query()
            ->where('status', BillLifecycle::Recorded->value)
            ->sum('balance_amount');
    }

    private function applyTerms(): void
    {
        if ($this->supplierId === '' || $this->billOn === '') {
            return;
        }

        $supplier = Supplier::query()->find((int) $this->supplierId);

        if ($supplier === null) {
            return;
        }

        $terms = PaymentTerms::normalise($supplier->payment_terms);

        if (PaymentTerms::isValid($terms)) {
            $this->dueOn = PaymentTerms::dueOn($terms, Carbon::parse($this->billOn))->toDateString();
        }
    }

    public function record(RecordBill $action): void
    {
        $this->error = null;

        if (! Gate::allows('bills.create')) {
            $this->error = __('Recording a supplier bill needs the bills.create ability.');

            return;
        }

        $linked = $this->shape !== 'lines';

        $rules = [
            'supplierId' => ['required', 'integer'],
            'billOn' => ['required', 'date'],
            'dueOn' => ['required', 'date', 'after_or_equal:billOn'],
        ];

        if ($linked) {
            $rules['shape'] = ['required', 'in:'.implode(',', [BillLinkedSource::FuelDelivery->value, BillLinkedSource::StockReceipt->value])];
            $rules['obligationId'] = ['required', 'string'];
        } else {
            $rules['rows.*.description'] = ['required_with:rows.*.amount', 'max:160'];
            $rules['rows.*.category'] = ['required_with:rows.*.amount', 'in:'.implode(',', BillCategory::values())];
            $rules['rows.*.amount'] = ['required', 'numeric', 'min:0.01'];
        }

        $validated = $this->validate($rules);

        $base = CurrentCompany::model()->base_currency_code;
        $lines = [];

        if (! $linked) {
            foreach ($this->rows as $index => $row) {
                $amount = trim($row['amount'] ?? '');

                if ($amount === '') {
                    continue;
                }

                try {
                    $net = Money::ofMajor($amount, $base);
                } catch (\DomainException $exception) {
                    $this->error = 'Line '.($index + 1).': '.$exception->getMessage();

                    return;
                }

                $lines[] = new BillLineInput(
                    description: trim($row['description']),
                    category: BillCategory::from($row['category']),
                    netMinor: $net->minorUnits,
                    truckId: trim($row['truck'] ?? '') !== '' ? (int) $row['truck'] : null,
                    tripId: trim($row['trip'] ?? '') !== '' ? (string) $row['trip'] : null,
                );
            }
        }

        try {
            $bill = $action->execute(
                supplierId: (int) $validated['supplierId'],
                lines: $lines,
                billOn: Carbon::parse($validated['billOn']),
                dueOn: Carbon::parse($validated['dueOn']),
                supplierDocNo: $this->supplierDocNo !== '' ? $this->supplierDocNo : null,
                note: $this->note !== '' ? $this->note : null,
                linkedSource: $linked ? BillLinkedSource::from($validated['shape']) : null,
                linkedSourceId: $linked ? (string) $validated['obligationId'] : null,
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->bills, $this->outstandingMinor);

        $this->reset(['supplierDocNo', 'note', 'shape', 'obligationId']);
        $this->rows = [$this->blankRow()];

        $this->redirectRoute('accounting.bills.show', ['bill' => $bill->id]);
    }

    public function money(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    /**
     * The badge is the derived settlement when the bill is live (§10.4's axis two, copied sideways), and
     * the lifecycle only when the document has left the money question entirely.
     */
    public function stateTone(Bill $bill): string
    {
        return match (true) {
            $bill->status === BillLifecycle::Void => 'neutral',
            $bill->status === BillLifecycle::Draft => 'warning',
            $bill->balance_amount === 0 => 'success',
            $bill->isOverdue() => 'danger',
            default => 'info',
        };
    }

    public function stateLabel(Bill $bill): string
    {
        if ($bill->status !== BillLifecycle::Recorded) {
            return $bill->status->label();
        }

        return $bill->settlementState()->label();
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Bills') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Every supplier document this company has recorded, with the expense and input VAT it put into the ledger. Outstanding payable: :amount.', ['amount' => $this->money($this->outstandingMinor)]) }}
        </x-ui.text>
    </header>

    @if ($error)
        <x-ui.alert tone="danger" :title="__('That bill was not recorded')">{{ $error }}</x-ui.alert>
    @endif

    @if (session('status'))
        <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
    @endif

    @can('bills.create')
        <x-ui.card :title="__('Record a supplier bill')" :description="__('The supplier\'s own document for work done for the fleet — a garage repair, a policy renewal. Recording it posts the expense, claims the input VAT, and opens the payable.')"
        >
            <form wire:submit="record" class="grid gap-4">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                    <div class="grid gap-1.5">
                        <label for="bill-supplier" class="text-xs font-medium text-fg-ink-secondary">{{ __('Supplier') }}</label>

                        <x-ui.select name="supplierId" id="bill-supplier" wire:model.live="supplierId">
                            <option value="">{{ __('Choose a supplier') }}</option>
                            @foreach ($this->suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->label() }} ({{ $supplier->payment_terms }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="supplierDocNo" :label="__('Supplier document no.')" :hint="__('Their number, as printed — ours is generated.')" wire:model="supplierDocNo" />
                    <x-ui.input type="date" name="billOn" :label="__('Bill date')" wire:model.live="billOn" required />
                    <x-ui.input type="date" name="dueOn" :label="__('Due')" :hint="__('Taken from the supplier\'s terms; edit if the paper says otherwise.')" wire:model="dueOn" required />
                </div>

                {{-- §20.36 — the first question is what the paper IS. A new cost is typed line by line;
                     a bill for something Phase 3 already posted takes the book's figure and no lines,
                     because a second expense debit would fund the same diesel or shelf twice. --}}
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] sm:items-end">
                    <div class="grid gap-1.5">
                        <label for="bill-shape" class="text-xs font-medium text-fg-ink-secondary">{{ __('This paper is') }}</label>

                        <x-ui.select name="shape" id="bill-shape" wire:model.live="shape">
                            <option value="lines">{{ __('A new cost — I will list its lines') }}</option>
                            <option value="fuel_delivery">{{ __('The bill for a bulk fuel delivery already in stock') }}</option>
                            <option value="stock_receipt">{{ __('The bill for a parts receipt already on the shelf') }}</option>
                        </x-ui.select>
                    </div>

                    @if ($shape !== 'lines')
                        <div class="grid gap-1.5">
                            <label for="bill-obligation" class="text-xs font-medium text-fg-ink-secondary">{{ __('Which movement') }}</label>

                            <x-ui.select name="obligationId" id="bill-obligation" wire:model.live="obligationId">
                                <option value="">{{ __('Choose the delivery or receipt this bill documents') }}</option>
                                @foreach ($this->obligations as $obligation)
                                    <option value="{{ $obligation->id }}">
                                        {{ $obligation->label }} · {{ $this->money($obligation->amountBase) }}
                                    </option>
                                @endforeach
                            </x-ui.select>

                            @if ($this->obligations === [])
                                <p class="text-xs text-fg-ink-muted">{{ __('Nothing is waiting for its paper: every posted delivery and receipt already has a bill, and a pump slip becomes an expense when the diesel is issued (§20.26).') }}</p>
                            @elseif ($this->chosenObligation === null && $obligationId !== '')
                                <p class="text-xs text-fg-danger">{{ __('That movement is no longer on the list — someone else just billed it, or it was released. Choose again.') }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($shape === 'lines')
                <fieldset class="grid gap-3">
                    <legend class="text-xs font-medium text-fg-ink-secondary">{{ __('Lines') }}</legend>

                    @foreach ($rows as $index => $row)
                        @php $needs = $this->categoryByCode[$row['category']] ?? null; @endphp
                        <div wire:key="bill-row-{{ $index }}" class="grid gap-2 rounded-lg border border-line-subtle p-3 sm:grid-cols-12 sm:items-end">
                            <div class="sm:col-span-4">
                                <x-ui.input
                                    :wire:model="'rows.'.$index.'.description'"
                                    :name="'rows.'.$index.'.description'"
                                    :label="$index === 0 ? __('What was done') : null"
                                    :aria-label="__('Description for line :n', ['n' => $index + 1])"
                                />
                            </div>

                            <div class="grid gap-1.5 sm:col-span-2">
                                <label for="bill-row-{{ $index }}-category" class="text-xs font-medium text-fg-ink-secondary">{{ $index === 0 ? __('Category') : '' }}</label>

                                <x-ui.select
                                    name="rows.{{ $index }}.category"
                                    id="bill-row-{{ $index }}-category"
                                    wire:model.live="rows.{{ $index }}.category"
                                    :aria-label="__('Category for line :n', ['n' => $index + 1])"
                                >
                                    <option value="">{{ __('Choose') }}</option>
                                    @foreach ($this->categories as $category)
                                        <option value="{{ $category['code'] }}">{{ $category['label'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div class="sm:col-span-2">
                                <x-ui.input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :wire:model.live.debounce.400ms="'rows.'.$index.'.amount'"
                                    :name="'rows.'.$index.'.amount'"
                                    :label="$index === 0 ? __('Net ('.CurrentCompany::model()->base_currency_code.')') : null"
                                    :aria-label="__('Net amount for line :n', ['n' => $index + 1])"
                                />
                            </div>

                            @if (($needs['truck'] ?? false))
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <label for="bill-row-{{ $index }}-truck" class="text-xs font-medium text-fg-ink-secondary">{{ $index === 0 ? __('Truck') : '' }}</label>

                                    <x-ui.select name="rows.{{ $index }}.truck" id="bill-row-{{ $index }}-truck" wire:model="rows.{{ $index }}.truck" :aria-label="__('Truck for line :n', ['n' => $index + 1])">
                                        <option value="">{{ __('Which truck') }}</option>
                                        @foreach ($this->trucks as $id => $registration)
                                            <option value="{{ $id }}">{{ $registration }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            @endif

                            @if (($needs['trip'] ?? false))
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <label for="bill-row-{{ $index }}-trip" class="text-xs font-medium text-fg-ink-secondary">{{ $index === 0 ? __('Trip') : '' }}</label>

                                    <x-ui.select name="rows.{{ $index }}.trip" id="bill-row-{{ $index }}-trip" wire:model="rows.{{ $index }}.trip" :aria-label="__('Trip for line :n', ['n' => $index + 1])">
                                        <option value="">{{ __('Which trip') }}</option>
                                        @foreach ($this->trips as $id => $tripNo)
                                            <option value="{{ $id }}">{{ $tripNo }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            @endif

                            <div class="sm:col-span-2">
                                @if (count($rows) > 1)
                                    <x-ui.button variant="ghost" size="sm" type="button" wire:click="removeRow({{ $index }})" :aria-label="__('Remove line :n', ['n' => $index + 1])">
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                @endif
                            </div>

                            @if (($this->vatPreview['rows'][$index]['tax'] ?? 0) > 0)
                                <div class="text-xs text-fg-ink-muted sm:col-span-12">
                                    {{ __('VAT :vat at :rate — line total :gross', [
                                        'vat' => $this->money((int) $this->vatPreview['rows'][$index]['tax']),
                                        'rate' => $this->vatPreview['label'],
                                        'gross' => $this->money((int) ($this->vatPreview['rows'][$index]['net'] + $this->vatPreview['rows'][$index]['tax'])),
                                    ]) }}
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div class="flex items-center justify-between">
                        <x-ui.button variant="quiet" size="sm" type="button" wire:click="addRow">{{ __('Add line') }}</x-ui.button>

                        <div class="text-sm text-fg-ink-secondary">
                            {{ __('Net :net · VAT :vat · Bill total :gross', [
                                'net' => $this->money($this->enteredTotals['net']),
                                'vat' => $this->money($this->enteredTotals['tax']),
                                'gross' => $this->money($this->enteredTotals['gross']),
                            ]) }}
                            @if ($this->vatPreview === null)
                                <span class="text-fg-danger">{{ __('· no VAT rate is effective on the bill date') }}</span>
                            @endif
                        </div>
                    </div>
                </fieldset>
                @else
                    <x-ui.alert tone="info" :title="__('This bill posts nothing')">
                        @if ($this->chosenObligation !== null)
                            {{ __(':label — the ledger already owes :amount on 2110 from the movement itself (§20.36). This paper documents that figure; it will not be expensed a second time, and it carries no input VAT because the movement modelled none.', [
                                'label' => $this->chosenObligation->label,
                                'amount' => $this->money($this->chosenObligation->amountBase),
                            ]) }}
                        @else
                            {{ __('Choose the delivery or receipt this document is the bill for — its amount comes from the book, not from the keyboard.') }}
                        @endif
                    </x-ui.alert>
                @endif

                <x-ui.input name="note" :label="__('Note')" :hint="__('Anything a later reader of this bill needs — which work order, which claim.')" wire:model="note" />

                <x-ui.button type="submit" variant="primary">{{ __('Record bill') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card :title="__('Register')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Our number, theirs, or the supplier')" />

            <div class="grid gap-1.5">
                <label for="bill-state-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('State') }}</label>

                <x-ui.select name="stateFilter" id="bill-state-filter" wire:model.live="stateFilter">
                    <option value="">{{ __('Everything') }}</option>
                    <option value="open">{{ __('Still owed') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="void">{{ __('Void') }}</option>
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Bills')">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('Bill') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Supplier') }}</x-ui.table.th>
                    <x-ui.table.th nowrap>{{ __('Billed') }}</x-ui.table.th>
                    <x-ui.table.th nowrap>{{ __('Due') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('of which VAT') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Still owed') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($this->bills as $bill)
                <tr wire:key="bill-{{ $bill->id }}">
                    <x-ui.table.td>
                        <span class="fg-identifier font-medium text-fg-ink">{{ $bill->number }}</span>
                        <div class="text-xs text-fg-ink-muted">{{ $bill->supplier_doc_no ?? '—' }}</div>
                        @if ($bill->linked_source !== null)
                            <div class="text-xs text-fg-ink-muted">{{ __('documents a ') . mb_strtolower($bill->linked_source->label()) }}</div>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>{{ $bill->supplier?->name ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td nowrap>{{ $bill->bill_on->format('d M Y') }}</x-ui.table.td>
                    <x-ui.table.td nowrap>{{ $bill->due_on->format('d M Y') }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($bill->amount) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($bill->tax_amount) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $bill->status->isOpenItem() ? $this->money($bill->balance_amount) : '—' }}</x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :tone="$this->stateTone($bill)">{{ $this->stateLabel($bill) }}</x-ui.badge>

                        @if ($bill->isOverdue())
                            <div class="text-xs text-fg-danger">{{ __('past due since ') . $bill->due_on->format('d M Y') }}</div>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td numeric nowrap>
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            :href="route('accounting.bills.show', ['bill' => $bill->id])"
                            wire:navigate
                        >{{ __('Open') }}</x-ui.button>
                    </x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <x-ui.table.td colspan="9">
                        <x-ui.empty-state
                            :title="__('No bills match')"
                            :description="__('Record the supplier\'s document above — a garage repair, an insurance policy, anything the fleet owes and no Phase 3 movement already posted.')"
                        />
                    </x-ui.table.td>
                </tr>
            @endforelse
        </x-ui.table>

        <div class="pt-3">
            {{ $this->bills->links() }}
        </div>
    </x-ui.card>
</div>
