<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\ChargeableBasis;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\ChargeableType;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * §16's rule that a delivery earns what the job promised: turn the approved proof into a chargeable.
 *
 * The input is the **job's quote**, not the trip's loads. The customer agreed a price on the job
 * (§4.1), and a POD that arrives with 32.4 tonnes where the job estimated 32 does not renegotiate
 * the agreement — it says what the agreed basis (per tonne, per trip) is applied to.
 *
 * `source_uuid` is `pod:{id}`, which is the idempotency key: approval retried, a raced click, or a
 * queue replay lands on the same row and the same amount. A `per_km` job has no chargeable here —
 * a POD records a delivered quantity, not a measured distance — and returns null rather than
 * inventing one; Phase 4's billing screen raises that line by hand.
 */
final class BuildChargeablesFromPod
{
    public function __invoke(ProofOfDelivery $pod): ?Chargeable
    {
        $trip = Trip::query()->findOrFail($pod->trip_id);
        $job = Job::query()->findOrFail($trip->job_id);

        if ($job->currency_code === null || $job->quoted_amount <= 0 || $job->rate_type === null) {
            return null;
        }

        $basis = ChargeableBasis::fromRate($job->rate_type);

        if ($basis === null) {
            return null;
        }

        $quantity = (float) ($pod->quantity_received_tonnes_10_2 ?? 0);

        if ($basis === ChargeableBasis::PerTonne && $quantity <= 0) {
            return null;
        }

        [$amount, $base] = $this->amounts($job, $basis, $quantity);

        if ($amount <= 0 || $base <= 0) {
            return null;
        }

        $source = 'pod:'.$pod->id;

        $attributes = [
            'id' => (string) Str::ulid(),
            'trip_id' => $trip->id,
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
            'type' => ChargeableType::Freight->value,
            'basis' => $basis->value,
            'description' => trim(sprintf('%s · %s', $job->job_no, (string) ($job->commodity ?? 'freight'))),
            'currency_code' => $job->currency_code,
            'amount' => $amount,
            'base_amount' => $base,
            // Accepted delivery and current paperwork: the only thing missing before an invoice is
            // the invoice. Phase 4 moves it to `billed`, never this table.
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => $source,
        ];

        try {
            return Chargeable::query()->firstOrCreate(['source_uuid' => $source], $attributes);
        } catch (UniqueConstraintViolationException $exception) {
            // Two approvals raced; the winner's row is the answer, not a second charge.
            $existing = Chargeable::query()->where('source_uuid', $source)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: int, 1: int} foreign minor units, base minor units
     */
    private function amounts(Job $job, ChargeableBasis $basis, float $quantity): array
    {
        if ($basis !== ChargeableBasis::PerTonne) {
            return [$job->quoted_amount, $job->base_amount];
        }

        // The quote's amount is a rate per tonne, so the charge is rate × delivered quantity. The
        // quantity column is 2 dp, which is the same scale the multiply is rounded at (HALF_UP,
        // §5.5); parsing it as a string keeps brick from receiving a float's binary noise.
        $scale = (string) number_format($quantity, 2, '.', '');

        return [
            $this->multiply($job->quoted_amount, $scale),
            $this->multiply($job->base_amount, $scale),
        ];
    }

    private function multiply(int $minorRate, string $quantity): int
    {
        return BigDecimal::of((string) $minorRate)
            ->multipliedBy(BigDecimal::of($quantity))
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }
}
