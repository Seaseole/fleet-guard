<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Documents\Actions\RegisterDocument;
use App\Domain\Kernel\Documents\Actions\UploadDocumentVersion;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Operations\Kiosk\KioskAuthority;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\TripAccessScope;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;

/**
 * §9.6 — the driver's capture, written through the same door the office uses.
 *
 * The kiosk is not a second data path. This Action is what the office screen would call too: one trip, one
 * manifest, one set of rules, so a POD typed on a phone at a border post and a POD keyed in by a clerk
 * cannot become two different shapes of fact. §9.6 says exactly that, and the reason is §10.2 — the states
 * have gate conditions, and a second implementation is a second place to forget one.
 *
 * **The offline retry is the normal case.** A `client_uuid` that has been seen returns the row it created,
 * unchanged, instead of a second delivery note: a driver with one bar of signal submits, the phone times
 * out, the phone submits again. Refusing the second attempt would leave the yard believing the goods were
 * never signed for, and de-duplicating after the write would already have doubled the paperwork.
 *
 * `arrived → pod_pending` is the only trip move a capture may make. `pod_pending → delivered` is an office
 * decision (§10.2: "a driver may never transition to delivered"), and {@see AdvanceTrip} is the thing that
 * enforces it — this Action asks for the move and does not reach around the guard to avoid it.
 */
final class CaptureProofOfDelivery
{
    public function __construct(
        private readonly AdvanceTrip $advance,
        private readonly RegisterDocument $register,
        private readonly UploadDocumentVersion $upload,
    ) {}

    /**
     * @param  array{receiver_name?: string, quantity_tonnes?: string|float|null, delivered_at?: string|null, exceptions?: list<string>|null, none_declared?: bool, load_id?: int|null}  $facts
     * @param  array{signature?: DocumentInput, photo?: DocumentInput}  $files
     */
    public function __invoke(
        TripAccessToken $token,
        string $secret,
        string $pin,
        array $facts,
        array $files = [],
        ?string $clientUuid = null,
    ): ProofOfDelivery {
        return DB::transaction(function () use ($token, $secret, $pin, $facts, $files, $clientUuid): ProofOfDelivery {
            KioskAuthority::verified($token, $secret, $pin, TripAccessScope::Pod);

            if ($clientUuid !== null && trim($clientUuid) !== '') {
                $seen = ProofOfDelivery::query()->where('client_uuid', trim($clientUuid))->first();

                if ($seen !== null) {
                    return $seen;
                }
            }

            $trip = Trip::query()->findOrFail($token->trip_id);

            // `arrived` is the move this capture answers, and `pod_pending` is the second attempt after
            // a rejection — the trip deliberately stays there when a proof is sent back, so a driver
            // who fixes the photograph must be able to submit again. Every other state is either
            // before the drop-off or after the office has already accepted one.
            if (! in_array($trip->status, [TripStatus::Arrived, TripStatus::PodPending], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Trip %s is %s, so there is nothing to prove delivery of from the road.',
                    $trip->trip_no,
                    $trip->status->label(),
                ));
            }

            // Everything §10.2 will ask for is checked **before** the first row or byte is written: a
            // refusal after the uploads would leave a signed note on the disk that no POD points at.
            $this->assertComplete($facts, $files);

            $pod = ProofOfDelivery::create([
                'trip_id' => $trip->id,
                'load_id' => isset($facts['load_id']) ? (int) $facts['load_id'] : null,
                'status' => PodStatus::Pending,
                'client_uuid' => $clientUuid === null || trim($clientUuid) === '' ? null : trim($clientUuid),
                'captured_by_token_id' => $token->id,
            ]);

            foreach (['signature' => 'signature', 'photo' => 'photo'] as $slot => $kind) {
                if (! isset($files[$slot])) {
                    continue;
                }

                /** @var DocumentInput $input */
                $input = $files[$slot];

                $document = $this->register->handle(
                    type: DocumentType::Pod,
                    title: sprintf('%s %s (%s)', $trip->trip_no, $kind, $pod->id),
                    morphType: 'proof_of_delivery',
                    morphId: $pod->id,
                );

                $this->upload->handle($document, $input);

                if ($slot === 'signature') {
                    $pod->forceFill(['signature_document_id' => $document->id])->save();
                }
            }

            $exceptions = $facts['exceptions'] ?? null;

            $pod->forceFill([
                'receiver_name' => isset($facts['receiver_name']) ? trim((string) $facts['receiver_name']) : null,
                'quantity_received_tonnes_10_2' => $facts['quantity_tonnes'] ?? null,
                'delivered_at' => $facts['delivered_at'] ?? now(),
                'exceptions' => is_array($exceptions) ? $exceptions : null,
            ])->save();

            if (($missing = $pod->approvalFindings()) !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'The proof is not complete yet: %s.',
                    implode(', ', $missing),
                ));
            }

            $pod->forceFill(['status' => PodStatus::Submitted->value])->save();

            if ($trip->status === TripStatus::Arrived) {
                ($this->advance)($trip, TripStatus::PodPending, null, ActorKind::Kiosk);
            }

            return $pod->refresh();
        });
    }

    /**
     * §10.2's gate, asked of the facts as they arrive rather than after the uploads.
     *
     * Signature and photo, a receiver, a quantity, and an answered exceptions question — the same list
     * {@see ProofOfDelivery::approvalFindings()} re-checks on the stored row. Asking it twice is the
     * cheap half of the deal: the row is what gets approved, and these are the things that must never
     * reach the disk when one is missing.
     *
     * @param  array<string, mixed>  $facts
     * @param  array{signature?: DocumentInput, photo?: DocumentInput}  $files
     */
    private function assertComplete(array $facts, array $files): void
    {
        $missing = [];

        if (! isset($files['signature'])) {
            $missing[] = 'no signature on file';
        }

        if (! isset($files['photo'])) {
            $missing[] = 'no photo of the delivered load';
        }

        if (! isset($facts['receiver_name']) || trim((string) $facts['receiver_name']) === '') {
            $missing[] = 'no receiver is named';
        }

        if (! isset($facts['quantity_tonnes']) || $facts['quantity_tonnes'] === '') {
            $missing[] = 'no quantity received';
        }

        $exceptions = $facts['exceptions'] ?? null;

        if ($exceptions === null) {
            $missing[] = 'the exceptions question is unanswered';
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The proof is not complete yet: %s.',
                implode(', ', $missing),
            ));
        }

        if (! is_array($exceptions)) {
            throw new \InvalidArgumentException('Exceptions are a list of short sentences, or an empty list when nothing went wrong.');
        }

        if ($exceptions === [] && ($facts['none_declared'] ?? false) !== true) {
            throw new \InvalidArgumentException('Say that there were no exceptions, or list what went wrong — an empty answer is not the same as "nothing" (§10.2).');
        }
    }
}
