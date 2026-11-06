<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Route;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Books an enquiry (JOB, Phase 2's "jobs → quotes → trips").
 *
 * The job number is allocated here rather than at quote time because a job is *referenced* from the
 * moment it exists — the customer's WhatsApp message, the waybill, the board all name `JOB-00042` —
 * and the "number at issue, never at creation" rule is about financial documents, where a number
 * is a claim that money moved. An operational reference is a label, and the design says so: never reused
 * matters more here than having no gaps.
 *
 * Both master rows are read through their own tenant-scoped queries, so a dispatcher cannot book work
 * against another company's customer or corridor by guessing an id.
 */
final class CreateJob
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function __invoke(JobDto $dto, ?int $actorId = null): Job
    {
        return DB::transaction(function () use ($dto, $actorId): Job {
            /** @var Customer $customer */
            $customer = Customer::query()->findOrFail($dto->customerId);
            Route::query()->findOrFail($dto->routeId);

            if (! $customer->active) {
                throw new InvalidArgumentException(sprintf(
                    'Customer "%s" is inactive, so new work cannot be booked against them.',
                    $customer->name,
                ));
            }

            /** @var Job $job */
            $job = Job::create([
                'job_no' => $this->numbers->allocate('job', null, $actorId),
                'customer_id' => $dto->customerId,
                'route_id' => $dto->routeId,
                'commodity' => $dto->commodity,
                'tonnes_10_2' => $dto->tonnes,
                'required_load_on' => $dto->requiredLoadOn,
                'required_delivery_on' => $dto->requiredDeliveryOn,
                'terms' => $dto->terms,
                'notes' => $dto->notes,
            ]);

            return $job;
        });
    }
}
