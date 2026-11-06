<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Money\RateResolver;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts a price on a job (the `enquiry → quoted`, the money rules).
 *
 * `base_amount` is computed here and never recomputed later (authoritative, frozen at write
 * time), which is why the rate and its date must be *named or resolvable* at the moment of quoting —
 * a quote whose base figure came from a rate that moves next week would silently change what the
 * jobs board says the corridor is worth.
 *
 * A job already `quoted` may be re-quoted without a state move: The design has no `quoted → enquiry`
 * edge, and a second number on the same offer is an edit of the offer, not a new lifecycle event.
 */
final class QuoteJob
{
    public function __construct(private readonly RateResolver $rates) {}

    public function __invoke(Job $job, JobQuoteDto $dto, ?int $actorId = null): Job
    {
        return DB::transaction(function () use ($job, $dto, $actorId): Job {
            $baseCode = strtoupper(CurrentCompany::model()->base_currency_code);
            $foreignCode = $dto->currency();

            $foreign = Money::ofMajor($dto->amountMajor, Currency::of($foreignCode));

            if ($foreign->minorUnits <= 0) {
                throw new InvalidArgumentException('A quote of zero is not an offer — state a price or decline the job.');
            }

            $quotedOn = $dto->rateDate === null
                ? CarbonImmutable::now(CurrentCompany::timezone())->startOfDay()
                : CarbonImmutable::parse($dto->rateDate)->startOfDay();

            if ($foreignCode === $baseCode) {
                $baseMinor = $foreign->minorUnits;
            } else {
                $rate = $dto->rate ?? $this->rates->resolve($foreignCode, Carbon::instance($quotedOn))?->rate();

                if ($rate === null || (float) $rate <= 0) {
                    throw new InvalidArgumentException(sprintf(
                        'There is no %s/%s rate for %s and none was stated. Quote in %s, name the rate, or record the day\'s rate first.',
                        $foreignCode,
                        $baseCode,
                        $quotedOn->toDateString(),
                        $baseCode,
                    ));
                }

                $fx = Fx::foreignToBase(
                    foreignMinorUnits: $foreign->minorUnits,
                    foreignCurrency: $foreignCode,
                    rate: (string) $rate,
                    baseCurrency: $baseCode,
                    source: $dto->rate === null ? FxRateSource::Contract : FxRateSource::Manual,
                    rateDate: $quotedOn,
                );

                $baseMinor = $fx->baseAmount()->minorUnits;
            }

            $job->fill([
                'rate_type' => $dto->rateType->value,
                'quoted_amount' => $foreign->minorUnits,
                'currency_code' => $foreignCode,
                'base_amount' => $baseMinor,
                'quoted_on' => $quotedOn->toDateString(),
                'required_load_on' => $dto->requiredLoadOn ?? $job->required_load_on?->toDateString(),
                'required_delivery_on' => $dto->requiredDeliveryOn ?? $job->required_delivery_on?->toDateString(),
                'terms' => $dto->terms ?? $job->terms,
            ]);

            if ($job->status === JobStatus::Enquiry) {
                $job->moveTo(JobStatus::Quoted, null, [], ActorKind::User, $actorId);
            }

            $job->save();

            return $job->refresh();
        });
    }
}
