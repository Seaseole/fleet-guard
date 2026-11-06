<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The `arrived → delivered` decision, made by an office seat on the evidence the driver sent.
 *
 * This is the half of the kiosk that keeps a driver out of the money: the capture says "I handed it over",
 * the approval says "and the paperwork is good enough to bill on". Two different actors, and the design's own
 * words — *a driver may never transition to `delivered`* — are enforced twice over: here by the permission,
 * and in {@see AdvanceTrip} by the `ActorKind::Kiosk` guard on any state that requires an office role.
 *
 * The trip move is not optional and not separate: a POD approved against a trip still sitting in
 * `pod_pending` is a delivery the board cannot see, so one transaction does both or neither.
 */
final class ApprovePod
{
    public function __construct(
        private readonly AdvanceTrip $advance,
        private readonly BuildChargeablesFromPod $chargeables,
    ) {}

    public function __invoke(ProofOfDelivery $pod, ?int $actorId = null): ProofOfDelivery
    {
        return DB::transaction(function () use ($pod, $actorId): ProofOfDelivery {
            if (! Gate::allows('pod.approve')) {
                throw new \InvalidArgumentException('Approving a proof of delivery needs the pod.approve ability.');
            }

            if ($pod->status === PodStatus::Approved) {
                throw new \InvalidArgumentException('That proof is already approved — a delivery is accepted once.');
            }

            if ($pod->status !== PodStatus::Submitted) {
                throw new \InvalidArgumentException(sprintf(
                    'There is nothing to approve: the proof is %s.',
                    $pod->status->label(),
                ));
            }

            if (($findings = $pod->approvalFindings()) !== []) {
                throw new \InvalidArgumentException('This proof cannot be accepted yet: '.implode(', ', $findings).'.');
            }

            $trip = Trip::query()->findOrFail($pod->trip_id);

            $pod->forceFill(['status' => PodStatus::Approved->value])->save();

            if ($trip->status === TripStatus::PodPending) {
                ($this->advance)($trip, TripStatus::Delivered, $actorId, ActorKind::User);
            }

            // The delivery is now a fact, so the job's promised price becomes a chargeable in the
            // same transaction. A trip that was already `delivered` (the kiosk closed it another
            // way) still gets its chargeable: the proof, not the state move, is what earns it.
            ($this->chargeables)($pod);

            return $pod->refresh();
        });
    }
}
