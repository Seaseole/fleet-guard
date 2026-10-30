<?php

use App\Domain\Kernel\Cutover\Actions\ApplyCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\ImportCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\PostOpeningTrialBalance;
use App\Domain\Kernel\Cutover\Actions\ReverseOpeningTrialBalance;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Models\CutoverRow;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
 * §15.2 — legacy intake: the opening trial balance and Phase 1's master data.
 *
 * One screen because it is one workflow: choose what the file is, upload it, read the errors, then
 * let the Owner act. The trial balance becomes a journal (`cutover.post`); every other dataset is
 * applied to its module's master tables (`cutover.post` as well — the Owner's decision, not the
 * accountant's). The route asks only for `cutover.import`; the policy is consulted per action, so an
 * accountant is never sent to a password prompt for a button they cannot press.
 *
 * Validation is a write. The batch and its rows are stored before anything is applied, so a rejected
 * file keeps the exact line numbers the accountant corrects the spreadsheet against, and the
 * downloadable error report is built from the same rows the table shows.
 */
new #[Title('Cutover')] class extends Component
{
    use WithFileUploads, WithPagination;

    /** The batch being reviewed, carried in the URL so a reload keeps the file in front of you. */
    #[Url(as: 'batch')]
    public string $batchId = '';

    /** Which dataset the next upload is. Kept in the URL so "re-upload the same kind of file" survives a reload. */
    #[Url(as: 'dataset')]
    public string $dataset = 'opening_trial_balance';

    /** @var \Illuminate\Http\UploadedFile|null */
    public $upload = null;

    public string $paste = '';

    /** `errors` keeps a 5 000-row file readable; `all` is one click away for a spot check. */
    public string $rowFilter = 'errors';

    public string $postedOn = '';

    public string $reversalReason = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->postedOn = now()->toDateString();

        if (CutoverDataset::tryFrom($this->dataset) === null) {
            $this->dataset = CutoverDataset::OpeningTrialBalance->value;
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'rowFilter') {
            $this->resetPage('rows');
        }

        if ($property === 'dataset') {
            $this->reset('upload', 'paste');
            $this->error = null;
        }
    }

    public function validateFile(ImportCutoverBatch $import): void
    {
        $this->error = null;

        abort_unless(Gate::allows('create', CutoverBatch::class), 403);

        $this->validate([
            'dataset' => ['required', Rule::enum(CutoverDataset::class)],
            'upload' => ['nullable', 'file', 'max:2048'],
            'paste' => ['nullable', 'string'],
        ]);

        $contents = $this->fileContents();

        if ($contents === null) {
            $this->addError('upload', __('Choose a CSV file or paste its contents first.'));

            return;
        }

        $dataset = $this->selectedDataset();

        $batch = $import->handle(
            $dataset,
            $contents,
            $this->upload instanceof \Illuminate\Http\UploadedFile ? $this->upload->getClientOriginalName() : 'pasted.csv',
            (int) auth()->id(),
        );

        $this->reset('upload', 'paste', 'rowFilter');
        $this->batchId = $batch->public_id;
        $this->resetPage('rows');
        $this->resetPage('history');
        unset($this->batch, $this->rows, $this->history);

        $ready = $dataset->isMasterData()
            ? __('File validated: :n rows, ready for the Owner to apply.', ['n' => $batch->valid_row_count])
            : __('File validated: :n rows, ready for the Owner to commit.', ['n' => $batch->valid_row_count]);

        $batch->status === CutoverBatchStatus::Validated
            ? $this->dispatch('toast', message: $ready, kind: 'success')
            : $this->dispatch('toast', message: __('File rejected with :n problem(s) — the error report says which lines.', ['n' => $batch->error_row_count + count($batch->errors())]), kind: 'danger');
    }

    /**
     * Returns the bytes to validate, or null when neither input carries anything.
     *
     * The file wins over the paste because a file is the act the accountant can point at later;
     * the paste box exists for the case the spreadsheet cannot leave their screen.
     */
    private function fileContents(): ?string
    {
        if ($this->upload !== null) {
            $contents = file_get_contents($this->upload->getRealPath());

            return $contents === false ? null : $contents;
        }

        return trim($this->paste) === '' ? null : $this->paste;
    }

    public function openBatch(string $publicId): void
    {
        $this->batchId = $publicId;
        $this->rowFilter = 'errors';
        $this->reset('reversalReason');
        $this->resetPage('rows');
        unset($this->batch, $this->rows);
    }

    public function commit(PostOpeningTrialBalance $post): void
    {
        $this->error = null;
        $batch = $this->batch;

        if ($batch === null) {
            return;
        }

        abort_unless(Gate::allows('post', $batch), 403);

        if (! $this->recentlyConfirmed()) {
            return;
        }

        $this->validate([
            'postedOn' => ['required', 'date'],
        ]);

        try {
            $post->handle($batch, CarbonImmutable::parse($this->postedOn)->startOfDay(), (int) auth()->id());
        } catch (CutoverRejected|PostingRejected $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->reset('postedOn');
        $this->postedOn = now()->toDateString();
        unset($this->batch, $this->rows, $this->history);

        $this->dispatch('toast', message: __('Opening balances committed to the ledger.'), kind: 'success');
    }

    public function reverse(ReverseOpeningTrialBalance $reverse): void
    {
        $this->error = null;
        $batch = $this->batch;

        if ($batch === null) {
            return;
        }

        abort_unless(Gate::allows('reverse', $batch), 403);

        if (! $this->recentlyConfirmed()) {
            return;
        }

        $this->validate(
            ['reversalReason' => ['required', 'string', 'max:255']],
            ['reversalReason.required' => __('A reversal needs the reason that will appear in the trail.')],
        );

        try {
            $reverse->handle($batch, $this->reversalReason, (int) auth()->id());
        } catch (CutoverRejected|PostingRejected $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->reset('reversalReason');
        unset($this->batch, $this->rows, $this->history);

        $this->dispatch('toast', message: __('Opening balances reversed — a mirror entry now nets the original.'), kind: 'success');
    }

    /**
     * The master-data half of the Owner's side: the handler upserts every valid row inside one
     * transaction, so this button either changes the register or changes nothing.
     */
    public function applyBatch(ApplyCutoverBatch $apply): void
    {
        $this->error = null;
        $batch = $this->batch;

        if ($batch === null) {
            return;
        }

        abort_unless(Gate::allows('apply', $batch), 403);

        if (! $this->recentlyConfirmed()) {
            return;
        }

        try {
            $result = $apply->handle($batch, (int) auth()->id());
        } catch (CutoverRejected $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->batch, $this->rows, $this->history);

        $this->dispatch('toast', message: __(':n row(s) applied to the :dataset register.', [
            'n' => $result->valid_row_count,
            'dataset' => $result->dataset->label(),
        ]), kind: 'success');
    }

    public function selectedDataset(): CutoverDataset
    {
        return CutoverDataset::tryFrom($this->dataset) ?? CutoverDataset::OpeningTrialBalance;
    }

    public function isTrialBalance(): bool
    {
        return ! $this->selectedDataset()->isMasterData();
    }

    /** @return array<string, string> */
    public function datasetOptions(): array
    {
        $options = [];

        foreach (CutoverDataset::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function datasetColumns(): string
    {
        $dataset = $this->selectedDataset();

        return $dataset->isMasterData()
            ? implode(', ', $dataset->previewColumns())
            : 'account_code, debit, credit (account_name and description optional)';
    }

    public function datasetDescription(): string
    {
        return $this->selectedDataset()->summary();
    }

    /**
     * §9.3 marks `cutover.post` ⚑, and the whole screen is an ordinary read for the accountant, so
     * the re-auth cannot live on the route. `auth.password_confirmed_at` is the clock Fortify's own
     * `RequirePassword` writes — the same one the documents delete reads, not a second implementation.
     */
    private function recentlyConfirmed(): bool
    {
        $confirmed = (int) session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmed <= (int) config('fleet.identity.reauth_window_seconds')) {
            return true;
        }

        session(['url.intended' => url()->current().($this->batchId === '' ? '' : '?batch='.$this->batchId)]);
        $this->redirectRoute('password.confirm');

        return false;
    }

    #[Computed]
    public function batch(): ?CutoverBatch
    {
        if ($this->batchId === '') {
            return null;
        }

        $batch = CutoverBatch::query()->where('public_id', $this->batchId)->first();

        return $batch === null || Gate::denies('view', $batch) ? null : $batch;
    }

    /** @return LengthAwarePaginator<int, \App\Domain\Kernel\Cutover\Models\CutoverRow> */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $batch = $this->batch;

        if ($batch === null) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1, ['pageName' => 'rows']);
        }

        return $batch->rows()
            ->when($this->rowFilter === 'errors', fn ($query) => $query->where('status', 'error'))
            ->paginate(25, pageName: 'rows');
    }

    /** @return LengthAwarePaginator<int, CutoverBatch> */
    #[Computed]
    public function history(): LengthAwarePaginator
    {
        return CutoverBatch::query()->orderByDesc('id')->paginate(10, pageName: 'history');
    }

    public function money(int $minorUnits, string $currencyCode): string
    {
        return Money::ofMinor($minorUnits, $currencyCode)->format($currencyCode);
    }

    public function base(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function statusTone(CutoverBatchStatus $status): string
    {
        return match ($status) {
            CutoverBatchStatus::Validated => 'accent',
            CutoverBatchStatus::Posted, CutoverBatchStatus::Applied => 'success',
            CutoverBatchStatus::Reversed => 'warning',
            CutoverBatchStatus::Rejected => 'danger',
        };
    }

    public function statusIcon(CutoverBatchStatus $status): string
    {
        return match ($status) {
            CutoverBatchStatus::Validated => 'signal',
            CutoverBatchStatus::Posted, CutoverBatchStatus::Applied => 'check-circle',
            CutoverBatchStatus::Reversed => 'arrow-path',
            CutoverBatchStatus::Rejected => 'x-circle',
        };
    }

    /**
     * One preview cell of a master-data row. The payload keeps the file's own column names, so this
     * is the same lookup the error report does — money formatted in the row's currency, flags as
     * yes/no, and a stored secret only ever as its mask.
     */
    public function masterCell(CutoverRow $row, string $column, CutoverBatch $batch): string
    {
        $payload = $row->payload ?? [];
        $value = $payload[$column] ?? null;

        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('yes') : __('no');
        }

        if (is_int($value) && in_array($column, $batch->dataset->moneyColumns(), true)) {
            $currency = $payload['currency_code'] ?? null;

            return $this->money($value, is_string($currency) && $currency !== '' ? $currency : $batch->currency_code);
        }

        return (string) $value;
    }
}; ?>

    <div class="grid grid-cols-1 gap-6">
        <header class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Cutover') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Bring the legacy books across in two steps: upload a file and validate it, then let the Owner act. The opening trial balance becomes one journal; master data is applied to the registers. A rejected file is corrected by re-upload — the error report names the lines.') }}
            </x-ui.text>
        </header>

        @if ($error !== null)
            <x-ui.alert tone="danger" :title="__('The books refused this')">{{ $error }}</x-ui.alert>
        @endif

        <x-ui.card
            :title="__('Validate a file')"
            :description="__('2 MB and 5 000 rows at most. Nothing reaches the books until the Owner commits or applies it.')"
        >
            <form class="grid gap-4" wire:submit="validateFile">
                <div class="grid gap-1.5">
                    <label for="dataset" class="text-xs font-medium text-fg-ink-secondary">{{ __('What is this file?') }}</label>
                    <x-ui.select name="dataset" id="dataset" wire:model.live="dataset">
                        @foreach ($this->datasetOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.text size="xs" color="muted">{{ $this->datasetDescription() }}</x-ui.text>
                </div>

                <x-ui.input
                    type="file"
                    wire:model="upload"
                    name="upload"
                    accept=".csv,.txt,text/csv,text/plain"
                    :label="__('CSV file')"
                    :hint="__('Exported from the legacy system, or saved from the spreadsheet.')"
                    :error="$errors->first('upload')"
                />

                <div class="grid gap-1.5">
                    <label for="paste" class="text-xs font-medium text-fg-ink-secondary">{{ __('…or paste the rows') }}</label>
                    <textarea
                        id="paste"
                        wire:model="paste"
                        rows="6"
                        spellcheck="false"
                        placeholder="{{ $this->datasetColumns() }}"
                        class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 font-mono text-xs text-fg-ink placeholder:text-fg-ink-muted"
                    ></textarea>

                    @error('paste')
                        <x-ui.text size="xs" class="text-danger">{{ $message }}</x-ui.text>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-fg-line pt-4">
                    <x-ui.text size="xs" color="muted" class="max-w-xl">
                        {{ __('Columns: :columns.', ['columns' => $this->datasetColumns()]) }}
                        @if ($this->isTrialBalance())
                            {{ __('Accounts that require a truck, trip, customer or driver cannot be imported yet — the report names them.') }}
                        @endif
                    </x-ui.text>

                    <x-ui.button variant="primary" type="submit">{{ __('Validate file') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        @if ($this->batch !== null)
            @php($batch = $this->batch)
            @php($balanced = $batch->total_debit === $batch->total_credit && $batch->total_debit > 0)

            <x-ui.card
                :title="$batch->dataset->label().($batch->original_filename ? ' — '.$batch->original_filename : '')"
                :description="__('Validated :when by upload. Line numbers match the file, so a rejected row is found where the spreadsheet shows it.', ['when' => $batch->created_at?->format('d M Y H:i')])"
            >
                <div class="grid gap-4">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <x-ui.badge :tone="$this->statusTone($batch->status)" :icon="$this->statusIcon($batch->status)">{{ $batch->status->label() }}</x-ui.badge>

                        <x-ui.text size="sm">
                            {{ __(':rows rows', ['rows' => $batch->row_count]) }}
                            · {{ __(':ok valid', ['ok' => $batch->valid_row_count]) }}
                            · {{ __(':bad with problems', ['bad' => $batch->error_row_count]) }}
                        </x-ui.text>

                        @unless ($batch->dataset->isMasterData())
                            <x-ui.text size="sm" class="font-mono">
                                {{ __('Dr') }} {{ $this->money($batch->total_debit, $batch->currency_code) }}
                                · {{ __('Cr') }} {{ $this->money($batch->total_credit, $batch->currency_code) }}
                            </x-ui.text>

                            @if ($balanced)
                                <x-ui.badge tone="success" icon="check">{{ __('balanced') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger" icon="exclamation-triangle">{{ __('out of balance') }}</x-ui.badge>
                            @endif
                        @endunless

                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            :href="route('accounting.cutover.errors', ['batch' => $batch->id])"
                            icon="arrow-down-tray"
                        >{{ __('Error report (.csv)') }}</x-ui.button>
                    </div>

                    @if ($batch->errors() !== [])
                        <x-ui.alert tone="danger" :title="__('The file as a whole was refused')">
                            <ul class="grid list-disc gap-1 ps-4">
                                @foreach ($batch->errors() as $batchError)
                                    <li>{{ $batchError }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Applied)
                        <x-ui.alert tone="success" :title="__('Applied to the register')">
                            {{ __(':rows row(s) became :dataset records on :date. Corrections happen on that register, not by re-applying this batch.', [
                                'rows' => $batch->valid_row_count,
                                'dataset' => mb_strtolower($batch->dataset->label()),
                                'date' => $batch->applied_at?->format('d M Y H:i'),
                            ]) }}
                        </x-ui.alert>
                    @endif

                    @unless ($batch->dataset->isMasterData())
                        @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Posted)
                            <x-ui.alert tone="info" :title="__('Posted to the ledger')">
                                {{ __('The opening balances are on the books at :date. A correction is a reversal, not an edit.', ['date' => $batch->posted_on?->format('d M Y')]) }}
                            </x-ui.alert>
                        @endif

                        @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Reversed)
                            <x-ui.alert tone="warning" :title="__('Reversed')">
                                {{ __('A mirror entry posted on :date nets the original. The ledger keeps both.', ['date' => $batch->reversed_at?->format('d M Y')]) }}
                            </x-ui.alert>
                        @endif

                        @can('post', $batch)
                            @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Validated)
                                <form class="flex flex-wrap items-end gap-3 border-t border-fg-line pt-4" wire:submit="commit">
                                    <div class="grid gap-1.5">
                                        <label for="commit-date" class="text-xs font-medium text-fg-ink-secondary">{{ __('Commit on') }}</label>
                                        <input
                                            id="commit-date"
                                            type="date"
                                            wire:model="postedOn"
                                            class="rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                                        />
                                    </div>

                                    <x-ui.button variant="outline" type="submit" icon="check">{{ __('Commit the opening balances') }}</x-ui.button>

                                    <x-ui.text size="xs" color="muted">
                                        {{ __('Posts one journal from the valid rows. Asking for your password again is the point: this moves the ledger.') }}
                                    </x-ui.text>
                                </form>
                            @endif
                        @else
                            @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Validated)
                                <x-ui.text size="sm" color="muted">
                                    {{ __('Validated and waiting for the Owner to commit it.') }}
                                </x-ui.text>
                            @endif
                        @endcan

                        @can('reverse', $batch)
                            @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Posted)
                                <form class="grid gap-3 border-t border-fg-line pt-4 sm:grid-cols-[minmax(0,2fr)_auto]" wire:submit="reverse">
                                    <x-ui.input
                                        wire:model="reversalReason"
                                        name="reversalReason"
                                        :label="__('Reason for the reversal')"
                                        :hint="__('Stored on the reversing entry and the operational trail.')"
                                        required
                                    />

                                    <div class="self-end">
                                        <x-ui.button variant="danger" type="submit" icon="arrow-path">{{ __('Reverse the opening balances') }}</x-ui.button>
                                    </div>
                                </form>
                            @endif
                        @endcan
                    @else
                        @if ($batch->status === \App\Domain\Kernel\Cutover\CutoverBatchStatus::Validated)
                            @can('apply', $batch)
                                <form class="flex flex-wrap items-center gap-3 border-t border-fg-line pt-4" wire:submit="applyBatch">
                                    <x-ui.button variant="outline" type="submit" icon="check">{{ __('Apply to the :dataset register', ['dataset' => mb_strtolower($batch->dataset->label())]) }}</x-ui.button>

                                    <x-ui.text size="xs" color="muted">
                                        {{ __('Upserts every valid row in one transaction, matched by its natural key. Asking for your password again is the point: this changes the master files.') }}
                                    </x-ui.text>
                                </form>
                            @else
                                <x-ui.text size="sm" color="muted">
                                    {{ __('Validated and waiting for the Owner to apply it.') }}
                                </x-ui.text>
                            @endcan
                        @endif
                    @endunless

                    <div class="flex flex-wrap items-center gap-2 border-t border-fg-line pt-4">
                        <x-ui.button
                            variant="{{ $rowFilter === 'errors' ? 'outline' : 'ghost' }}"
                            size="sm"
                            type="button"
                            wire:click="$set('rowFilter', 'errors')"
                        >{{ __('Problems (:n)', ['n' => $batch->error_row_count]) }}</x-ui.button>

                        <x-ui.button
                            variant="{{ $rowFilter === 'all' ? 'outline' : 'ghost' }}"
                            size="sm"
                            type="button"
                            wire:click="$set('rowFilter', 'all')"
                        >{{ __('All rows (:n)', ['n' => $batch->row_count]) }}</x-ui.button>
                    </div>

                    @if ($batch->dataset->isMasterData())
                        <x-ui.table :caption="__('Rows in this cutover batch')">
                            <thead>
                                <tr>
                                    <x-ui.table.th class="w-16">{{ __('Line') }}</x-ui.table.th>
                                    @foreach ($batch->dataset->previewColumns() as $column)
                                        <x-ui.table.th class="whitespace-nowrap">{{ $column }}</x-ui.table.th>
                                    @endforeach
                                    <x-ui.table.th>{{ __('Problem') }}</x-ui.table.th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->rows as $row)
                                    <tr wire:key="cutover-row-{{ $row->id }}">
                                        <x-ui.table.td class="font-mono text-xs">{{ $row->line_number }}</x-ui.table.td>
                                        @foreach ($batch->dataset->previewColumns() as $column)
                                            <x-ui.table.td class="whitespace-nowrap font-mono text-xs">{{ $this->masterCell($row, $column, $batch) }}</x-ui.table.td>
                                        @endforeach
                                        <x-ui.table.td>
                                            @if ($row->isValid())
                                                <x-ui.badge tone="success" icon="check">{{ __('ok') }}</x-ui.badge>
                                            @else
                                                <span class="text-danger">{{ implode(' ', $row->errorList()) }}</span>
                                            @endif
                                        </x-ui.table.td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($batch->dataset->previewColumns()) + 2 }}">
                                            <x-ui.empty-state
                                                icon="check-circle"
                                                :title="$rowFilter === 'errors' ? __('No rows have a problem') : __('This file has no rows')"
                                                :description="$rowFilter === 'errors'
                                                    ? __('The report is empty — which is what the phase exits on.')
                                                    : __('Upload a file to validate it.')"
                                            />
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </x-ui.table>
                    @else
                        <x-ui.table :caption="__('Rows in this cutover batch')">
                            <thead>
                                <tr>
                                    <x-ui.table.th class="w-16">{{ __('Line') }}</x-ui.table.th>
                                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                                    <x-ui.table.th class="text-right">{{ __('Debit') }}</x-ui.table.th>
                                    <x-ui.table.th class="text-right">{{ __('Credit') }}</x-ui.table.th>
                                    <x-ui.table.th>{{ __('Problem') }}</x-ui.table.th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->rows as $row)
                                    <tr wire:key="cutover-row-{{ $row->id }}">
                                        <x-ui.table.td class="font-mono text-xs">{{ $row->line_number }}</x-ui.table.td>
                                        <x-ui.table.td>
                                            <span class="font-mono text-xs text-fg-ink">{{ $row->account_code }}</span>
                                            @if ($row->account_name)
                                                <span class="text-fg-ink-secondary"> — {{ $row->account_name }}</span>
                                            @endif
                                        </x-ui.table.td>
                                        <x-ui.table.td class="text-right font-mono text-xs">
                                            {{ $row->debit_minor > 0 ? $this->money($row->debit_minor, $batch->currency_code) : '—' }}
                                        </x-ui.table.td>
                                        <x-ui.table.td class="text-right font-mono text-xs">
                                            {{ $row->credit_minor > 0 ? $this->money($row->credit_minor, $batch->currency_code) : '—' }}
                                        </x-ui.table.td>
                                        <x-ui.table.td>
                                            @if ($row->isValid())
                                                <x-ui.badge tone="success" icon="check">{{ __('ok') }}</x-ui.badge>
                                            @else
                                                <span class="text-danger">{{ implode(' ', $row->errorList()) }}</span>
                                            @endif
                                        </x-ui.table.td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            <x-ui.empty-state
                                                icon="check-circle"
                                                :title="$rowFilter === 'errors' ? __('No rows have a problem') : __('This file has no rows')"
                                                :description="$rowFilter === 'errors'
                                                    ? __('The report is empty — which is what the phase exits on.')
                                                    : __('Upload a file to validate it.')"
                                            />
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </x-ui.table>
                    @endif

                    @if ($this->rows->hasPages())
                        <div class="grid gap-3">
                            {{ $this->rows->links() }}
                            <x-ui.text size="sm" color="muted">
                                {{ __('Showing :first–:last of :total rows.', [
                                    'first' => $this->rows->firstItem(),
                                    'last' => $this->rows->lastItem(),
                                    'total' => $this->rows->total(),
                                ]) }}
                            </x-ui.text>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Cutover history')" :description="__('Every upload, whether it was committed, applied or not. A reversed batch stays here as the record of what was taken back.')">
            <x-ui.table :caption="__('Cutover batches')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Batch') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Dataset') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('File') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __('Rows') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __('Debit') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __('Credit') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('When') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->history as $entry)
                        <tr wire:key="cutover-batch-{{ $entry->id }}" @class(['bg-fg-surface-sunk' => $entry->public_id === $batchId])>
                            <x-ui.table.td class="font-mono text-xs">#{{ $entry->id }}</x-ui.table.td>
                            <x-ui.table.td class="whitespace-nowrap">{{ $entry->dataset->label() }}</x-ui.table.td>
                            <x-ui.table.td>{{ $entry->original_filename ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$this->statusTone($entry->status)">{{ $entry->status->label() }}</x-ui.badge>
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right font-mono text-xs">
                                {{ $entry->valid_row_count }}/{{ $entry->row_count }}
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right font-mono text-xs">{{ $entry->dataset->isMasterData() ? '—' : $this->money($entry->total_debit, $entry->currency_code) }}</x-ui.table.td>
                            <x-ui.table.td class="text-right font-mono text-xs">{{ $entry->dataset->isMasterData() ? '—' : $this->money($entry->total_credit, $entry->currency_code) }}</x-ui.table.td>
                            <x-ui.table.td class="whitespace-nowrap text-fg-ink-secondary">{{ $entry->created_at?->format('d M Y H:i') }}</x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <x-ui.button variant="ghost" size="sm" wire:click="openBatch('{{ $entry->public_id }}')">{{ __('Open') }}</x-ui.button>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-ui.empty-state
                                    icon="circle-stack"
                                    :title="__('No cutover file has been uploaded yet')"
                                    :description="__('Validate a file above and it will appear here with its outcome.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->history->hasPages())
                <div class="mt-4">
                    {{ $this->history->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
