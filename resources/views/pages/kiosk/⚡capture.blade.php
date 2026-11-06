<?php

declare(strict_types=1);

use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Operations\Actions\CaptureProofOfDelivery;
use App\Domain\Operations\Actions\RedeemKioskToken;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\TripAccessScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The driver's proof-of-delivery screen. One page, no login, one trip.
 *
 * Written to be used on a shared phone in a yard or at a border post, which sets every decision on it:
 * the fields are the four the gate actually asks about (who received it, how much arrived, what went
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

    /**
     * Left untyped on purpose: Livewire's temporary upload class has moved namespace more than once
     * (`TemporaryUploadedFile` under `Livewire\`, then under `Features\SupportFileUploads`), and a
     * property typed to the old name makes hydration fail with a type error before this page can say
     * anything. The value is always an `UploadedFile`; {@see save()} checks that rather than trusting it.
     */
    public $photo = null;

    /** Minted once per page load, not per submit — see the class note on the offline retry. */
    public string $clientUuid = '';

    public bool $done = false;

    /**
     * The page's own link, held so a Livewire update can re-open it.
     *
     * `/livewire/update` does not run the `kiosk` route middleware, so the request attributes and the
     * company context the initial GET established are gone by the first submit. The token is not a
     * secret beyond the URL that already carries it, and the snapshot is signed; these two values let
     * {@see token()} redeem the same link again on every update.
     */
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $secretKey = '';

    public function mount(): void
    {
        $this->clientUuid = (string) Str::uuid();
        $this->companyId = (string) request()->route('company');
        $this->secretKey = (string) request()->route('secret');

        if ($this->existingCapture() !== null) {
            $this->done = true;
        }
    }

    public function save(CaptureProofOfDelivery $capture): void
    {
        // Resolved first: on an update this is what re-opens the link the GET established.
        $token = $this->token();

        if ($token === null || ! $token->hasScope(TripAccessScope::Pod)) {
            $this->addError('form', __('This link does not open proof-of-delivery capture.'));

            return;
        }

        $this->validate([
            'receiverName' => ['required', 'string', 'max:120'],
            'quantityTonnes' => ['required', 'numeric', 'gt:0', 'lt:100000'],
            'pin' => ['required', 'string', 'size:6', 'regex:/\A\d{6}\z/'],
            // The design names the photo alongside the signature, and the Action refuses without one — asked
            // here so the driver sees which field is missing rather than a refusal about "the proof".
            'photo' => ['required', 'file', 'max:15728', 'mimes:jpg,jpeg,png,webp'],
            'signatureData' => ['required', 'string', 'min:80'],
        ]);

        $files = [];

        try {
            $files['signature'] = $this->signatureInput();

            // The temporary upload *is* an `UploadedFile` (it extends one), so it goes to the document
            // module as-is. `instance()` was a Livewire 2 method and does not exist on the v4 object.
            if (! $this->photo instanceof UploadedFile) {
                throw new RuntimeException('The photo did not arrive as a file. Take it again.');
            }

            $files['photo'] = DocumentInput::fromUploadedFile($this->photo);
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
                    // is a blank answer, and the gate refuses to treat those as the same fact.
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

    /** The trip this link is bound to — the only trip this page can speak about. */
    public function tripFor(): ?Trip
    {
        return $this->token()?->trip;
    }

    /** The plate the signature is being attached to — the fuel screen's own rule, kept identical here. */
    public function truckLabel(): string
    {
        $trip = $this->tripFor();

        if ($trip?->truck_id === null) {
            return __('this trip’s truck');
        }

        return Truck::query()->whereKey($trip->truck_id)->first()?->label() ?? __('this trip’s truck');
    }

    /**
     * Whether this page's own door is open.
     *
     * The link is opened by the middleware as long as it is live, so a token issued for `fuel` alone can
     * still land here by typing the URL. Rendering the POD form to it would offer a capture the Action
     * refuses one field at a time; asking up front lets the page point at the screen the link does open.
     */
    public function opensPod(): bool
    {
        return $this->token()?->hasScope(TripAccessScope::Pod) ?? false;
    }

    public function opensFuel(): bool
    {
        return $this->token()?->hasScope(TripAccessScope::Fuel) ?? false;
    }

    public function fuelUrl(): string
    {
        return route('kiosk.fuel', [
            'company' => CurrentCompany::id(),
            'secret' => $this->secret(),
        ]);
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

        if ($token instanceof TripAccessToken) {
            return $token;
        }

        if ($this->companyId === '' || $this->secretKey === '') {
            return null;
        }

        try {
            $token = app(RedeemKioskToken::class)->fromSecret((int) $this->companyId, $this->secretKey, (string) request()->ip());
        } catch (\InvalidArgumentException) {
            return null;
        }

        // The rest of this request (render included) reads the token through the same attribute the
        // route middleware sets, so the link is redeemed once per update, not once per property read.
        request()->attributes->set('kioskToken', $token);
        request()->attributes->set('kioskSecret', $this->secretKey);

        return $token;
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
     * in a column instead would put the signature outside the retention, MIME-truth and signed-download
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

    <header class="grid gap-2">
        <x-ui.heading size="lg" level="1">{{ __('Proof of delivery') }}</x-ui.heading>

        @if ($trip !== null)
            {{-- The identity strip DESIGN-SYSTEM.md §6 demands — same shape as the fuel screen, so a driver
                 moving between the two recognises the rule rather than relearning the page. --}}
            <p class="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-fg-md bg-fg-surface-sunk px-4 py-3">
                <span class="text-sm text-fg-ink-secondary">{{ __('Signing for') }}</span>
                <span class="fg-identifier text-base font-medium text-fg-ink">{{ $trip->trip_no }}</span>
                <span class="text-fg-ink-muted" aria-hidden="true">·</span>
                <span class="fg-identifier text-base font-medium text-fg-ink">{{ $this->truckLabel() }}</span>
            </p>
        @endif
    </header>

    @if ($trip === null)
        <x-ui.alert tone="danger" :title="__('This link is closed')">
            {{ __('Ask the dispatcher for a new one.') }}
        </x-ui.alert>
    @elseif (! $this->opensPod())
        <x-ui.alert tone="warning" :title="__('This link does not open proof-of-delivery capture')">
            {{ __('The dispatcher chooses what a driver link may capture. Ask for one that names proof of delivery.') }}
        </x-ui.alert>

        @if ($this->opensFuel())
            <x-ui.button variant="outline" :href="$this->fuelUrl()">
                {{ __('Record fuel instead') }}
            </x-ui.button>
        @endif
    @elseif ($done)
        <x-ui.alert tone="success" :title="__('Sent')">
            {{ __('The office approves the delivery — :status.', ['status' => $this->podStatusLine()]) }}
        </x-ui.alert>

        <x-ui.button variant="outline" wire:click="$refresh">{{ __('Check again') }}</x-ui.button>

        @if ($this->opensFuel())
            <x-ui.button variant="ghost" size="sm" :href="$this->fuelUrl()">{{ __('Record fuel') }}</x-ui.button>
        @endif
    @else
        @if ($this->opensFuel())
            <div class="flex justify-end">
                <x-ui.button variant="ghost" size="sm" :href="$this->fuelUrl()">{{ __('Record fuel') }}</x-ui.button>
            </div>
        @endif

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

            <x-ui.textarea
                name="exceptionsText"
                wire:model="exceptionsText"
                :label="__('What went wrong? (one per line)')"
                :placeholder="__('Short damaged, two pallets wet…')"
            />

            <x-ui.checkbox
                name="nothingWrong"
                wire:model="nothingWrong"
                :label="__('Nothing went wrong — deliver the same as the manifest')"
            />

            <div class="grid gap-1">
                <label for="photo" class="text-xs font-medium text-fg-ink-secondary">{{ __('Photo of the delivered load') }}</label>
                <input
                    id="photo"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    wire:model="photo"
                    @error('photo') aria-invalid="true" aria-describedby="photo-error" @enderror
                    class="w-full text-sm text-fg-ink file:mr-3 file:rounded-fg-sm file:border-0 file:bg-fg-surface-sunk file:px-3 file:py-2"
                />
                @error('photo')
                    <p id="photo-error" class="flex items-center gap-1.5 text-xs text-danger" role="alert">
                        <x-ui.icon name="x-circle" size="3" />
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{--
                The signature canvas: Alpine owns the drawing, Livewire only ever sees the finished PNG.

                The backing store is sized from the rendered box (device-pixel-ratio aware), and every
                pointer position is mapped through the element's rect — the old fixed 600x200 canvas
                stretched by `w-full` put the stroke up to 1.7x away from the finger on a phone, which
                is most of the screens this page runs on. Pointer events cover mouse, touch and stylus
                in one path; the drawing survives a rotation because fit() re-projects the snapshot.
            --}}
            <div
                class="grid gap-2"
                x-data="{
                    drawing: false,
                    ratio: 1,
                    fit() {
                        const c = $refs.c;
                        const rect = c.getBoundingClientRect();
                        if (rect.width === 0) return;
                        const snapshot = c.width > 0 ? c.toDataURL('image/png') : null;
                        this.ratio = window.devicePixelRatio || 1;
                        c.width = Math.round(rect.width * this.ratio);
                        c.height = Math.round(rect.height * this.ratio);
                        const ctx = c.getContext('2d');
                        ctx.lineWidth = 3 * this.ratio;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        if (snapshot) {
                            const img = new Image();
                            img.onload = () => ctx.drawImage(img, 0, 0, c.width, c.height);
                            img.src = snapshot;
                        }
                    },
                    point(e) {
                        const rect = $refs.c.getBoundingClientRect();
                        return { x: (e.clientX - rect.left) * this.ratio, y: (e.clientY - rect.top) * this.ratio };
                    },
                    start(e) {
                        $refs.c.setPointerCapture(e.pointerId);
                        const p = this.point(e);
                        this.drawing = true;
                        const ctx = $refs.c.getContext('2d');
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                    },
                    move(e) {
                        if (! this.drawing) return;
                        const p = this.point(e);
                        const ctx = $refs.c.getContext('2d');
                        ctx.lineTo(p.x, p.y);
                        ctx.stroke();
                    },
                    stop() {
                        if (! this.drawing) return;
                        this.drawing = false;
                        $wire.set('signatureData', $refs.c.toDataURL('image/png'));
                    },
                    clear() {
                        const c = $refs.c;
                        c.getContext('2d').clearRect(0, 0, c.width, c.height);
                        $wire.set('signatureData', '');
                    },
                }"
                x-init="fit(); new ResizeObserver(() => fit()).observe($refs.c)"
            >
                <span id="signature-label" class="text-xs font-medium text-fg-ink-secondary">{{ __('Signature — draw with a finger or stylus') }}</span>

                {{-- Deliberately not tabbable, and no `tabindex` here: drawing a signature needs a finger or a
                     stylus, so a focus stop that cannot be operated from the keyboard promises more than it
                     delivers. The keyboard route on this screen is the buttons around it. A typed-name
                     alternative is the honest answer for a driver who cannot draw, and it is not built. --}}
                <canvas
                    x-ref="c"
                    role="img"
                    aria-labelledby="signature-label"
                    @pointerdown.prevent="start($event)"
                    @pointermove.prevent="move($event)"
                    @pointerup="stop()"
                    @pointercancel="stop()"
                    class="h-44 w-full touch-none rounded-fg-sm border border-dashed border-fg-line-strong bg-fg-surface"
                ></canvas>

                <x-ui.button type="button" variant="ghost" size="sm" class="justify-self-start" x-on:click="clear()">
                    {{ __('Clear the signature') }}
                </x-ui.button>

                @error('signatureData')
                    <p class="flex items-center gap-1.5 text-xs text-danger" role="alert">
                        <x-ui.icon name="x-circle" size="3" />
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <x-ui.input name="pin" wire:model="pin" :label="__('6-digit PIN from the dispatcher')" type="text" inputmode="numeric" autocomplete="one-time-code" required />

            @error('form')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.button type="submit" size="lg" class="w-full">{{ __('Send the proof') }}</x-ui.button>
        </form>
    @endif
</div>
