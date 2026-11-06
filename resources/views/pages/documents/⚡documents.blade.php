<?php

use App\Domain\Kernel\Documents\Actions\DeleteDocumentVersion;
use App\Domain\Kernel\Documents\Actions\UploadDocumentVersion;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentLink;
use App\Domain\Kernel\Documents\Exceptions\DocumentNotDownloadable;
use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use App\Domain\Kernel\Documents\Exceptions\LifecycleTransitionRefused;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle as DocumentLifecycle;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Pagination;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The document register (Phase 0's "a signed document download").
 *
 * The signed URL is minted on the click and never at render. A sixty-second TTL rendered into the
 * table would produce a link that is dead before anybody's hand reaches the mouse, and a dead link
 * reads as a broken screen rather than as what it is — an expired authorisation.
 *
 * Every refusal keeps its own message. `InvalidDocumentFile`, `DocumentNotDownloadable` and
 * `LifecycleTransitionRefused` say what happened and which clause required it; collapsing them into
 * "something went wrong" would delete the only explanation the operator gets.
 */
new #[Title('Documents')] class extends Component {
    use ConfirmsRecentPassword;
    use WithFileUploads;

    public string $versioningId = '';

    /** @var \Illuminate\Http\UploadedFile|null */
    public $upload = null;

    public string $reason = '';

    public ?string $error = null;

    public ?string $downloadError = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $lifecycle = '';

    #[Url('page')]
    public int $page = 1;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'lifecycle'], true)) {
            $this->page = 1;
            unset($this->documents, $this->authorizedCount);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'lifecycle', 'page']);
        unset($this->documents, $this->authorizedCount);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->type !== '' || $this->lifecycle !== '';
    }

    public function startVersioning(string $documentId): void
    {
        if ($this->versioningId !== $documentId) {
            $this->reset(['upload', 'reason']);
        }

        $this->versioningId = $documentId;
        $this->reset('error');
    }

    public function stopVersioning(): void
    {
        $this->reset(['versioningId', 'upload', 'reason', 'error']);
    }

    public function versioning(): ?Document
    {
        return $this->versioningId === ''
            ? null
            : Document::query()->with('currentVersion')->find($this->versioningId);
    }

    /**
     * The register's rows, filtered and narrowed in SQL.
     *
     * The visibility used to be a PHP `filter()` over the whole table, which meant the register
     * read every document to show twenty-five — and a count read them all a second time. The scope
     * carries the same rule as the policy; pagination belongs to the database.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        $page = max(1, $this->page);

        return new Pagination(
            $this->registerQuery()->with('currentVersion')->forPage($page, 25)->get(),
            $this->authorizedCount,
            25,
            $page,
            ['path' => url()->current(), 'query' => request()->query()],
        );
    }

    #[Computed]
    public function authorizedCount(): int
    {
        return $this->registerQuery()->reorder()->count();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Document> */
    private function registerQuery(): \Illuminate\Database\Eloquent\Builder
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return Document::query()
            ->visibleTo($user)
            ->when($this->search !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where('title', 'like', $like);
            })
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->lifecycle !== '', fn ($query) => $query->where('lifecycle', $this->lifecycle))
            ->orderByRaw("case when lifecycle in ('draft','current') then 0 else 1 end")
            ->orderByRaw('case when expires_on is null then 1 else 0 end')
            ->orderBy('expires_on')
            ->orderBy('id');
    }

    public function saveVersion(UploadDocumentVersion $versions): void
    {
        $this->error = null;
        $document = $this->versioning();

        if ($document === null) {
            $this->error = __('Choose the document the file belongs to first.');

            return;
        }

        abort_unless(Gate::allows('upload', $document), 403);

        $this->validate([
            'upload' => ['required', 'file', 'max:'.(int) ceil($document->type->maxBytes() / 1024)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            // The validation rule above already requires it; this is the narrowing that lets the
            // Action receive a file rather than a nullable property.
            if (! $this->upload instanceof \Illuminate\Http\UploadedFile) {
                $this->error = __('The upload did not arrive as a file.');

                return;
            }

            $version = $versions->handle(
                $document,
                DocumentInput::fromUploadedFile($this->upload),
                (int) auth()->id(),
                $this->reason !== '' ? $this->reason : null,
            );

            $this->reset(['upload', 'reason']);
            unset($this->documents, $this->visibleDocuments, $this->authorizedCount);

            $this->dispatch('toast', message: __('Version :n of :title filed.', [
                'n' => $version->version_seq,
                'title' => $document->title,
            ]), kind: 'success');
        } catch (InvalidDocumentFile|LifecycleTransitionRefused $refused) {
            $this->error = $refused->getMessage();
        }
    }

    /**
     * Sign, then hand the browser the URL in the same action.
     *
     * The policy is asked before the link is minted, so a person who may not read the document does
     * not receive a live URL for it even for sixty seconds.
     */
    public function download(string $versionId, DocumentLink $link): void
    {
        $this->downloadError = null;
        $version = DocumentVersion::query()->find($versionId);

        if ($version === null) {
            $this->downloadError = __('That version is no longer on file.');

            return;
        }

        abort_unless(Gate::allows('download', $version->document), 403);

        try {
            $url = $link->temporaryFor($version);
        } catch (DocumentNotDownloadable $refused) {
            $this->downloadError = $refused->getMessage();

            return;
        }

        $this->js('window.location.href = '.json_encode($url, JSON_UNESCAPED_SLASHES));
    }

    public function deleteVersion(string $versionId, DeleteDocumentVersion $delete): void
    {
        $this->error = null;
        $this->downloadError = null;
        $version = DocumentVersion::query()->find($versionId);

        if ($version === null) {
            $this->error = __('That version is no longer on file.');

            return;
        }

        abort_unless(Gate::allows('deleteVersion', $version->document), 403);

        // Marks the documents delete column ⚑. The rest of the register is an ordinary read, so
        // the re-auth cannot live on the route without asking for a password to *look* at the list —
        // it lives on the action, through the same clock Fortify's RequirePassword writes.
        if (! $this->confirmRecentPassword()) {
            return;
        }

        // The design makes a reason mandatory for a destructive act. The operational_events CHECK enforces
        // it for a transition, but a delete removes the row rather than changing its state, so the
        // screen is the layer that has to ask — and it asks here, not in a confirm() with no field.
        $this->validate(
            ['reason' => ['required', 'string', 'max:255']],
            ['reason.required' => __('A delete needs the reason that will appear in the trail.')],
        );

        try {
            $delete->handle($version, $this->reason, (int) auth()->id());
        } catch (\DomainException | \InvalidArgumentException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->reset(['reason', 'upload']);
        unset($this->documents, $this->visibleDocuments, $this->authorizedCount);

        $this->dispatch('toast', message: __('Version deleted, with the bytes behind it.'), kind: 'success');
    }
}; ?>

    <div class="grid gap-6">
        <header class="fg-page-header grid gap-1.5">
            <x-ui.heading size="xl" level="1">{{ __('Documents') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Private disk, one current copy per document, and every download signed for sixty seconds and audited.') }}
            </x-ui.text>
        </header>

        @if ($downloadError !== null)
            <x-ui.alert tone="danger" :title="__('Download refused')">{{ $downloadError }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('Register')">
            <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_repeat(2,minmax(0,1fr))_auto]">
                <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Title')" />

                <div class="grid gap-1.5">
                    <label for="type-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Type') }}</label>
                    <x-ui.select name="type" id="type-filter" wire:model.live="type">
                        <option value="">{{ __('All types') }}</option>
                        @foreach (DocumentType::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="lifecycle-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Lifecycle') }}</label>
                    <x-ui.select name="lifecycle" id="lifecycle-filter" wire:model.live="lifecycle">
                        <option value="">{{ __('All states') }}</option>
                        @foreach (DocumentLifecycle::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.button
                    variant="ghost"
                    size="sm"
                    wire:click="clearFilters"
                    class="self-end"
                    :disabled="! $this->hasFilters()"
                >{{ __('Clear') }}</x-ui.button>
            </div>

            <x-ui.table :caption="__('Documents on file')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Document') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Type') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Lifecycle') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Validity') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Current copy') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->documents as $document)
                        @php($current = $document->currentVersion)
                        <tr wire:key="document-{{ $document->id }}">
                            <x-ui.table.td class="font-medium text-fg-ink">
                                {{ $document->title }}
                                @if ($document->confidential)
                                    <x-ui.badge tone="warning" icon="lock-closed">{{ __('confidential') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $document->type->label() }}</x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge
                                    :tone="$document->lifecycle === \App\Domain\Kernel\Documents\Lifecycle::Current ? 'success' : ($document->lifecycle === \App\Domain\Kernel\Documents\Lifecycle::Voided ? 'danger' : 'neutral')"
                                >{{ $document->lifecycle->label() }}</x-ui.badge>
                            </x-ui.table.td>
                            <x-ui.table.td>
                                @php($validity = $document->validity())
                                <x-ui.badge :tone="$validity->tone()" icon="clock">{{ $validity->label() }}</x-ui.badge>
                                @if ($document->expires_on)
                                    <div class="text-xs text-fg-ink-muted" title="{{ $document->expires_on->toDateString() }}">{{ $document->expires_on->format('d M Y') }}</div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>
                                @if ($current === null)
                                    <x-ui.text size="xs" color="muted">{{ __('no copy on file') }}</x-ui.text>
                                @else
                                    <span class="fg-identifier text-xs">v{{ $current->version_seq }}</span>
                                    <div class="text-xs text-fg-ink-muted">
                                        {{ $current->humanSize() }} · {{ $current->virus_scan_status->label() }}
                                    </div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td numeric nowrap>
                                @if ($current !== null)
                                    @can('download', $document)
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            wire:click="download('{{ $current->id }}')"
                                            wire:target="download('{{ $current->id }}')"
                                            wire:loading.attr="disabled"
                                        >
                                            <span wire:loading.remove wire:target="download('{{ $current->id }}')">{{ __('Download') }}</span>
                                            <span wire:loading wire:target="download('{{ $current->id }}')">{{ __('Preparing…') }}</span>
                                        </x-ui.button>
                                    @endcan
                                @endif

                                @can('upload', $document)
                                    <x-ui.button variant="ghost" size="sm" wire:click="startVersioning('{{ $document->id }}')">
                                        {{ __('File a copy') }}
                                    </x-ui.button>
                                @endcan
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @if ($this->authorizedCount === 0)
                                    <x-ui.empty-state
                                        icon="folder"
                                        :title="__('Nothing on file yet')"
                                        :description="__('Register a licence, an insurance policy, a permit or a proof of delivery and the expiry board starts working.')"
                                    />
                                @else
                                    <x-ui.empty-state
                                        icon="magnifying-glass"
                                        :title="__('No documents match these filters')"
                                        :description="__('Clear the search, type, or lifecycle filter to see the rest of the register.')"
                                    />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->documents->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->documents->appends(request()->query())->links() }}
                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total visible documents.', [
                            'first' => $this->documents->firstItem(),
                            'last' => $this->documents->lastItem(),
                            'total' => $this->documents->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif

            @can('documents.upload')
                <div class="mt-4">
                    <x-ui.link :href="route('documents.register')">{{ __('Register a document') }}</x-ui.link>
                </div>
            @endcan
        </x-ui.card>

        @if (($target = $this->versioning()) !== null)
            <x-ui.card :title="__('File a new copy of :title', ['title' => $target->title])">
                @if ($error !== null)
                    <x-ui.alert tone="danger" class="mb-4">{{ $error }}</x-ui.alert>
                @endif

                <div class="grid gap-4">
                    <x-ui.input
                        type="file"
                        name="upload"
                        wire:model="upload"
                        :label="__('The file')"
                        :hint="__('Accepted content::types · maximum:max MB. The type is read from the bytes, not the extension.', [
                            'types' => implode(', ', $target->type->allowedMimeTypes()),
                            'max' => number_format($target->type->maxBytes() / 1048576, 1),
                        ])"
                    />

                    <x-ui.input
                        type="text"
                        name="reason"
                        wire:model="reason"
                        :label="__('Why this replaces the copy on file')"
                        :error="$errors->first('reason')"
                    />

                    <div class="flex items-center gap-3">
                        <x-ui.button
                            wire:click="saveVersion"
                            wire:target="saveVersion"
                            wire:loading.attr="disabled"
                        >
                            <span wire:loading.remove wire:target="saveVersion">{{ __('File it') }}</span>
                            <span wire:loading wire:target="saveVersion">{{ __('Uploading…') }}</span>
                        </x-ui.button>

                        @if (($currentId = optional($target->currentVersion)->id) !== null)
                            @can('deleteVersion', $target)
                                <x-ui.button
                                    variant="danger"
                                    size="sm"
                                    wire:click="deleteVersion('{{ $currentId }}')"
                                    wire:target="deleteVersion('{{ $currentId }}')"
                                    wire:loading.attr="disabled"
                                    wire:confirm="{{ __('Delete this version and its bytes? The reason above is required.') }}"
                                >
                                    <span wire:loading.remove wire:target="deleteVersion('{{ $currentId }}')">{{ __('Delete current version') }}</span>
                                    <span wire:loading wire:target="deleteVersion('{{ $currentId }}')">{{ __('Deleting…') }}</span>
                                </x-ui.button>
                            @endcan
                        @endif

                        <x-ui.button variant="ghost" wire:click="stopVersioning">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @endif
    </div>
