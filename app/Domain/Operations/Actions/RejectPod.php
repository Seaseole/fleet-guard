<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\PodStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * §4.1's `submitted → rejected` — the POD machine's other end, and the reason it is not the trip's machine.
 *
 * A rejected proof leaves the trip exactly where it was (`pod_pending`), because the delivery is still
 * unproven, not unmade: the driver photographs it properly and submits again. Moving the trip backwards
 * would rewrite the road, and §10.2's rule against un-arriving a truck is the same rule the trip machine
 * already refuses.
 *
 * The reason is mandatory for the same reason §10.1 gives for every destructive state: `rejected` without
 * a sentence saying why is a decision nobody can review six weeks later.
 */
final class RejectPod
{
    public function __invoke(ProofOfDelivery $pod, string $reason): ProofOfDelivery
    {
        return DB::transaction(function () use ($pod, $reason): ProofOfDelivery {
            if (! Gate::allows('pod.approve')) {
                throw new \InvalidArgumentException('Rejecting a proof of delivery is the same decision as accepting it, so it needs the same ability (§9.3).');
            }

            if (trim($reason) === '') {
                throw new \InvalidArgumentException('Say what is wrong with it — the driver cannot fix an unnamed problem.');
            }

            if ($pod->status === PodStatus::Approved) {
                throw new \InvalidArgumentException('An approved proof is a fact the invoice may already be built on. Re-open the trip instead of unwinding it from here (§10.2).');
            }

            $exceptions = $pod->exceptions ?? [];
            $exceptions['rejected_for'] = trim($reason);

            $pod->forceFill([
                'status' => PodStatus::Rejected->value,
                'exceptions' => $exceptions,
            ])->save();

            return $pod->refresh();
        });
    }
}
