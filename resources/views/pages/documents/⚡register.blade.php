<?php

use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Documents\Actions\RegisterDocument;
use App\Domain\Kernel\Documents\Actions\UploadDocumentVersion;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use App\Domain\Kernel\Documents\Exceptions\LifecycleTransitionRefused;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\RetentionClass;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Registering a document, with its first copy if the person already has it (the `draft`).
 *
 * Two steps in one screen and two Actions behind it, because they are two decisions: filing the slot
 * in the register so something exists to chase, and attaching the paper. Submitting without a file
 * leaves a `draft` — which is the state the expiry board needs, because the licence that has not
 * arrived is the one that costs money.
 *
 * The expiry field is offered for every type and expected for the types that expire
 * ({@see DocumentType::expires()}). Leaving it blank on a licence is not refused: it is the true
 * answer when the date is on the paper and the paper is not here yet, and a form that forced a
 * guess would put a wrong date in the field the reminders are derived from.
 */
new #[Title('Register a document')] class extends Component {
    use WithFileUploads;

    public string $type = DocumentType::Licence->value;

    public string $title = '';

    public string $expiresOn = '';

    public string $retentionClass = RetentionClass::Operational->value;

    /** @var list<string> */
    public array $visibilityRoles = [];

    public bool $confidential = false;

    public string $notes = '';

    /** @var \Illuminate\Http\UploadedFile|null */
    public $file = null;

    public ?string $error = null;

    public ?string $registeredDocumentId = null;

    /**
     * The row this paper evidences, when the screen was reached from that row's page. Both are
     * URL-bound so a link can carry them; only the whitelist in {@see subject()} is honoured,
     * because the pair is written verbatim into the storage path.
     */
    #[Url('subject_type')]
    public string $subjectType = '';

    #[Url('subject_id')]
    public string $subjectId = '';

    /**
     * The resolved subject, or null when the screen was opened without one.
     *
     * A whitelist rather than a dynamic class lookup: `morph_type` ends up in the storage path, so a
     * value typed into the URL must never be able to name a class of the caller's choosing. Each
     * query runs through `BelongsToCompany`, so an id from another tenant reads as "no such
     * subject" — the same answer the detail pages give.
     *
     * @return array{type: string, id: string, label: string}|null
     */
    #[Computed]
    public function subject(): ?array
    {
        $id = trim($this->subjectId);

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        $label = match ($this->subjectType) {
            'truck' => Truck::query()->find((int) $id)?->label(),
            'trailer' => Trailer::query()->find((int) $id)?->label(),
            'driver' => Driver::query()->with('employee')->find((int) $id)?->employee?->name,
            'employee' => Employee::query()->find((int) $id)?->name,
            default => null,
        };

        if ($label === null) {
            return null;
        }

        return ['type' => $this->subjectType, 'id' => $id, 'label' => $label];
    }

    /**
     * The chosen type as the enum, for the template's hints.
     *
     * A `#[Computed]` rather than `$this->type` in Blade: the property is the stored string, and the
     * view needs `allowedMimeTypes()` and `maxBytes()` from the same config the Action enforces — a
     * second copy of that lookup in the template is a hint that can disagree with the rule.
     */
    #[Computed]
    public function currentType(): DocumentType
    {
        return DocumentType::from($this->type);
    }

    /**
     * @return list<string>
     */
    public function roleOptions(): array
    {
        return array_map(static fn (RoleKey $role): string => $role->value, RoleKey::cases());
    }

    public function save(RegisterDocument $register, UploadDocumentVersion $upload): void
    {
        $this->error = null;

        $validated = $this->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'title' => ['required', 'string', 'max:200'],
            'expiresOn' => ['nullable', 'date'],
            'retentionClass' => ['required', 'string', 'in:'.implode(',', array_column(RetentionClass::cases(), 'value'))],
            'visibilityRoles' => ['array'],
            'visibilityRoles.*' => ['string'],
            'confidential' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'max:'.(int) ceil(DocumentType::from($this->type)->maxBytes() / 1024)],
        ]);

        if ($this->registeredDocumentId === null) {
            abort_unless(Gate::allows('documents.upload'), 403);

            $subject = $this->subject;

            /** @var Document $document */
            $document = $register->handle(
                DocumentType::from($validated['type']),
                $validated['title'],
                morphType: $subject['type'] ?? null,
                morphId: $subject['id'] ?? null,
                expiresOn: $validated['expiresOn'] !== null && $validated['expiresOn'] !== ''
                    ? \Illuminate\Support\Carbon::parse($validated['expiresOn'])
                    : null,
                retention: RetentionClass::from($validated['retentionClass']),
                visibilityRoles: array_values(array_filter($validated['visibilityRoles'] ?? [])),
                confidential: (bool) $validated['confidential'],
                notes: $validated['notes'] !== '' ? $validated['notes'] : null,
            );

            $this->registeredDocumentId = $document->id;
        } else {
            /** @var Document $document */
            $document = Document::query()->findOrFail($this->registeredDocumentId);
            abort_unless(Gate::allows('upload', $document), 403);
        }

        if ($this->file !== null) {
            try {
                $upload->handle($document, DocumentInput::fromUploadedFile($this->file), (int) auth()->id());
            } catch (InvalidDocumentFile | LifecycleTransitionRefused $refused) {
                $this->error = $refused->getMessage();

                $this->dispatch('toast', message: __('The register entry was kept; choose another file and try again.'), kind: 'warning');

                return;
            }
        }

        $this->dispatch('toast', message: __(':title registered.', ['title' => $document->title]), kind: 'success');
        $this->reset(['registeredDocumentId', 'file']);
        $this->redirectRoute('documents.index', navigate: true);
    }
}; ?>

    <div class="grid w-full max-w-(--breakpoint-sm) gap-6">
        @php($locked = $registeredDocumentId !== null)

        <header class="fg-page-header grid gap-1.5">
            <x-ui.heading size="xl" level="1">{{ __('Register a document') }}</x-ui.heading>
            <x-ui.text>
                {{ __('A licence, permit, insurance policy, POD or customs paper — the register holds one entry per piece of paper and every version of it.') }}
            </x-ui.text>
        </header>

        @if ($error !== null)
            <x-ui.alert tone="danger" :title="__('The file was refused')">
                {{ $error }}
                <div class="mt-1 text-fg-ink-muted">{{ __('Choose another file below and try again; the entered details remain available.') }}</div>
            </x-ui.alert>
        @endif

        @if ($this->subject !== null)
            <x-ui.alert tone="info" :title="__('Filing against :label', ['label' => $this->subject['label']])">
                {{ __('The register will file this paper against that :type. The subject travels in the link, so it cannot be changed here — open the :type from its own page to file against a different row.', ['type' => __($this->subject['type'])]) }}
            </x-ui.alert>
        @endif

        <x-ui.card :title="__('What is this paper?')">
            <div class="grid gap-4">
                <div>
                    <label for="type" class="mb-1.5 block text-sm font-medium text-fg-ink">{{ __('Type') }}</label>
                    <x-ui.select name="type" id="type" wire:model.live="type" @disabled($locked)>
                        @foreach (\App\Domain\Kernel\Documents\DocumentType::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    @if (! $this->currentType->expires())
                        <x-ui.text size="xs" color="muted">{{ __('A :type records something that already happened and does not expire.', ['type' => strtolower($this->currentType->label())]) }}</x-ui.text>
                    @endif
                </div>

                <x-ui.input type="text" name="title" wire:model="title" :label="__('Title')" :disabled="$locked" required />

                <x-ui.input type="date" name="expiresOn" wire:model="expiresOn" :label="__('Expires on')" :hint="__('The expiry drives the validity badge and the reminder window.')" :disabled="$locked" />

                <div>
                    <label for="retentionClass" class="mb-1.5 block text-sm font-medium text-fg-ink">{{ __('Retention class') }}</label>
                    <x-ui.select name="retentionClass" id="retentionClass" wire:model="retentionClass" @disabled($locked)>
                        @foreach (\App\Domain\Kernel\Documents\RetentionClass::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input type="file" name="file" wire:model="file" :label="$locked ? __('Attach a replacement file') : __('The file (optional now)')" :hint="__('Accepted: :types · maximum :max MB — the content is sniffed, not the extension.', [
                    'types' => implode(', ', $this->currentType->allowedMimeTypes()),
                    'max' => number_format($this->currentType->maxBytes() / 1048576, 1),
                ])" />

                <x-ui.input type="text" name="notes" wire:model="notes" :label="__('Notes')" :disabled="$locked" />

                <div class="grid gap-2">
                    <span class="text-sm font-medium text-fg-ink">{{ __('Visible only to these roles — leave none ticked to keep it open to everyone with documents.view') }}</span>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        @foreach (\App\Domain\Kernel\Identity\RoleKey::cases() as $role)
                            <x-ui.checkbox :name="'visibility-'.$role->value" wire:model="visibilityRoles" value="{{ $role->value }}" :label="$role->label()" :disabled="$locked" />
                        @endforeach
                    </div>
                </div>

                <x-ui.checkbox name="confidential" wire:model="confidential" label="Confidential — restrict to the Owner and the roles named above" :disabled="$locked" />

                <div class="flex items-center gap-3">
                    <x-ui.button
                        wire:click="save"
                        wire:target="save"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="save">{{ $locked ? __('Attach file') : __('Register') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </x-ui.button>
                    <x-ui.button variant="ghost" :href="route('documents.index')">{{ $locked ? __('Back to register') : __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    </div>
