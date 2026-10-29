<?php

declare(strict_types=1);

use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\CaptureProofOfDelivery;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\TripAccessScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * §9.6 — the driver's proof-of-delivery screen. One page, no login, one trip.
 *
 * Written to be used on a shared phone in a yard or at a border post, which sets every decision on it:
 * the fields are the four §10.2's gate actually asks about (who received it, how much arrived, what went
 * wrong, and the picture and signature that say so) and nothing else — a long form on a cracked screen
 * with one bar of signal is how the exceptions column ends up empty;
 * the client UUID is minted once per page load so a retry after a timeout lands on the same row instead of
 * becoming a second delivery note; and the PIN is asked on every submit, because the link alone is
 * something a phone number is forwarded in a chat.
 *
 * There is no "save draft". A POD is either evidence or it is not, and half-captured paper on a trip
 * nobody can find later is the worst of both. The submit goes through
 * {@see \App\Domain\Operations\Actions\CaptureProofOfDelivery} — the same Action the office screen uses —
 * so the gate conditions cannot drift between the two doors.
 */
new #[Layout('layouts::auth')] #[Title('Proof of delivery')] class extends Component
{
    use WithFileUploads;

    public string $receiverName = '';

    public string $quantityTonnes = '';

    /** Newline-separated: a driver types three short problems faster than they fill three inputs. */
    public string $exceptionsText = '';

    public bool $nothingWrong = false;

    public string $pin = '';

    public string $signatureData = '';

    public ?\Livewire\TemporaryUploadedFile $photo = null;

    /** Minted once per page load, not per submit — see the class note on the offline retry. */
    public string $clientUuid = '';

    public bool $done = false;

    public function mount(): void
    {
        $this->clientUuid = (string) Str::uuid();

        if ($this->existingCapture() !== null) {
            $this->done = true;
        }
    }

    public function save(CaptureProofOfDelivery $capture): void
    {
        $this->validate([
            'receiverName' => ['required', 'string', 'max:120'],
            'quantityTonnes' => ['required', 'numeric', 'gt:0', 'lt:100000'],
            'pin' => ['required', 'string', 'size:6', 'regex:/\A\d{6}\z/'],
            // §10.2 names the photo alongside the signature, and the Action refuses without one — asked
            // here so the driver sees which field is missing rather than a refusal about "the proof".
            'photo' => ['required', 'file', 'max:15728', 'mimes:jpg,jpeg,png,webp'],
            'signatureData' => ['required', 'string', 'min:80'],
        ]);

        $token = $this->token();

        if ($token === null || ! $token->hasScope(TripAccessScope::Pod)) {
            $this->addError('form', __('This link does not open proof-of-delivery capture.'));

            return;
        }

        $files = [];

        try {
            $files['signature'] = $this->signatureInput();
            $files['photo'] = DocumentInput::fromUploadedFile($this->photo->instance());
        } catch (Throwable $unreadable) {
            $this->addError('form', $unreadable->getMessage());

            return;
        }

        $declared = array_values(array_filter(array_map('trim', preg_split('/\R+/', $this->exceptionsText) ?: [])));

        try {
            $capture(
                token: $token,
                secret: $this->secret(),
                pin: $this->pin,
                facts: [
                    'receiver_name' => $this->receiverName,
                    'quantity_tonnes' => $this->quantityTonnes,
                    // An empty list with the box ticked is "nothing went wrong"; an empty list without it
                    // is a blank answer, and §10.2's gate refuses to treat those as the same fact.
                    'exceptions' => $this->nothingWrong ? [] : ($declared === [] ? null : $declared),
                    'none_declared' => $this->nothingWrong,
                ],
                files: $files,
                clientUuid: $this->clientUuid,
            );
        } catch (Throwable $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->done = true;
        $this->reset(['pin', 'signatureData', 'photo']);
        $this->dispatch('toast', message: __('Sent. The office signs it off.'), kind: 'success');
    }

    /** The trip this link is bound to — the only trip this page can speak about (§9.6). */
    public function tripFor(): ?Trip
    {
        return $this->token()?->trip;
    }

    public function podStatusLine(): string
    {
        $pod = $this->existingCapture();

        return $pod === null ? '' : $pod->status->label();
    }

    /** A page reload after a successful submit must not invite a second attempt. */
    public function existingCapture(): ?ProofOfDelivery
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        return ProofOfDelivery::query()
            ->where('captured_by_token_id', $token->id)
            ->whereIn('status', [PodStatus::Submitted->value, PodStatus::Approved->value])
            ->latest('created_at')
            ->first();
    }

    private function token(): ?TripAccessToken
    {
        $token = request()->attributes->get('kioskToken');

        return $token instanceof TripAccessToken ? $token : null;
    }

    private function secret(): string
    {
        return (string) request()->attributes->get('kioskSecret');
    }

    /**
     * The drawn signature, turned into a file.
     *
     * A canvas hands over a data URL, and the document module takes a path it can read — so the image is
     * written to a temporary file the sniffer can inspect like any other upload. Storing the base64 string
     * in a column instead would put the signature outside §10.8's retention, MIME-truth and signed-download
     * rules, which is the one thing a POD must not be.
     */
    private function signatureInput(): DocumentInput
    {
        $comma = strpos($this->signatureData, ',');

        if ($comma === false || ! str_starts_with($this->signatureData, 'data:image/png')) {
            throw new RuntimeException('The signature was not captured. Draw it again with a finger or a stylus.');
        }

        $bytes = base64_decode(substr($this->signatureData, $comma + 1), true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The signature image could not be read. Draw it again.');
        }

        $path = 'kiosk/'.CurrentCompany::id().'-'.(string) Str::uuid().'.png';
        Storage::disk('local')->put($path, $bytes);

        $absolute = Storage::disk('local')->path($path);

        try {
            return new DocumentInput($absolute, 'image/png', 'signature.png');
        } finally {
            // The file is a carrier, not a record: the document module moves what it accepts into its own
            // storage, and an accepted signature must not also sit in plain view on the local disk.
            register_shutdown_function(static fn () => Storage::disk('local')->delete($path));
        }
    }
}; ?>

<div class="mx-auto grid w-full max-w-xl gap-5">
    @php($trip = $this->tripFor())

    <header class="grid gap-1">
        <x-ui.heading size="lg" level="1">{{ __('Proof of delivery') }}</x-ui.heading>

        @if ($trip !== null)
            <p class="text-sm text-fg-ink-secondary">
                {{ __('Trip :trip · :loads', ['trip' => $trip->trip_no, 'loads' => $trip->loads->count()]) }}
            </p>
        @endif
    </header>

    @if ($trip === null)
        <x-ui.alert tone="danger" :title="__('This link is closed')">
            {{ __('Ask the dispatcher for a new one.') }}
        </x-ui.alert>
    @elseif ($done)
        <x-ui.alert tone="success" :title="__('Sent')">
            {{ __('The office approves the delivery — :status.', ['status' => $this->podStatusLine()]) }}
        </x-ui.alert>

        <x-ui.button variant="outline" wire:click="$refresh">{{ __('Check again') }}</x-ui.button>
    @else
        <form class="grid gap-4" wire:submit="save">
            <x-ui.input name="receiverName" wire:model="receiverName" :label="__('Who received it?')" required />

            <x-ui.input
                name="quantityTonnes"
                wire:model="quantityTonnes"
                :label="__('Tonnes actually received')"
                type="number"
                step="0.01"
                numeric
                required
            />

            <div class="grid gap-2">
                <label for="exceptions" class="text-xs font-medium text-fg-ink-secondary">{{ __('What went wrong? (one per line)') }}</label>
                <textarea
                    id="exceptions"
                    wire:model="exceptionsText"
                    rows="3"
                    class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                    placeholder="{{ __('Short damaged, two pallets wet…') }}"
                ></textarea>

                <label class="flex items-center gap-2 text-sm text-fg-ink">
                    <input type="checkbox" wire:model="nothingWrong" class="rounded-fg-sm border-fg-line-strong" />
                    {{ __('Nothing went wrong — deliver the same as the manifest') }}
                </label>
            </div>

            <div class="grid gap-1">
                <label for="photo" class="text-xs font-medium text-fg-ink-secondary">{{ __('Photo of the delivered load') }}</label>
                <input
                    id="photo"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    wire:model="photo"
                    class="w-full text-sm text-fg-ink file:mr-3 file:rounded-fg-sm file:border-0 file:bg-fg-surface-sunk file:px-3 file:py-2"
                />
                @error('photo') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>

            {{-- The signature canvas: Alpine owns the drawing, Livewire only ever sees the finished PNG. --}}
            <div class="grid gap-1" x-data="{ drawing: false, start(e){ const p=e.touches?e.touches[0]:e; this.drawing=true; $refs.c.getContext('2d').beginPath(); $refs.c.getContext('2d').moveTo(p.offsetX,p.offsetY); }, move(e){ if(!this.drawing) return; const p=e.touches?e.touches[0]:e; const c=$refs.c.getContext('2d'); c.lineWidth=3; c.lineCap='round'; c.lineTo(p.offsetX,p.offsetY); c.stroke(); e.preventDefault(); }, stop(){ this.drawing=false; $wire.set('signatureData', $refs.c.toDataURL('image/png')); } }">
                <span class="text-xs font-medium text-fg-ink-secondary">{{ __('Signature — draw with a finger') }}</span>

                <canvas
                    x-ref="c"
                    width="600"
                    height="200"
                    @mousedown.prevent="start($event)"
                    @mousemove.prevent="move($event)"
                    @mouseup="stop()"
                    @mouseleave="stop()"
                    @touchstart.prevent="start($event)"
                    @touchmove.prevent="move($event)"
                    @touchend="stop()"
                    class="w-full touch-none rounded-fg-sm border border-dashed border-fg-line-strong bg-fg-surface"
                ></canvas>

                <button type="button" class="justify-self-start text-xs text-fg-ink-muted" x-on:click="$refs.c.getContext('2d').clearRect(0,0,600,200); $wire.set('signatureData','')">{{ __('Clear') }}</button>

                @error('signatureData') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>

            <x-ui.input name="pin" wire:model="pin" :label="__('6-digit PIN from the dispatcher')" type="text" inputmode="numeric" required />

            @error('form')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.button type="submit" class="w-full">{{ __('Send the proof') }}</x-ui.button>
        </form>
    @endif
</div>
