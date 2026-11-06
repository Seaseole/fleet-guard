<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\LoanFrequency;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns loan terms into the frozen instalment rows (the schedule is *stored*, and the
 * split is read from those rows, never recomputed at report time).
 *
 * Flat principal with declining interest on the outstanding balance — the shape the vehicle
 * facility quotes — with the integer remainder of the division landing on the **last** row so
 * the sum of the plan is exactly the principal (the residue rule, applied at origination).
 * Interest is floor-rounded per period; rounding drift is not a debt, and the lender's own
 * statement reconciles through the reconciliation screen, not a silently adjusted rate.
 */
final class AmortisationSchedule
{
    /**
     * @return list<array{seq: int, due_on: string, principal_due: int, interest_due: int, total_due: int}>
     */
    public static function build(
        int $principalMinor,
        string $annualRatePercent,
        LoanFrequency $frequency,
        CarbonImmutable $firstPaymentOn,
        int $count,
    ): array {
        if ($principalMinor <= 0 || $count <= 0) {
            throw new InvalidArgumentException('A schedule needs a positive principal and at least one repayment.');
        }

        if ((float) $annualRatePercent < 0) {
            throw new InvalidArgumentException('A negative interest rate is a data entry mistake, not a subsidy.');
        }

        $base = intdiv($principalMinor, $count);
        $rows = [];
        $remaining = $principalMinor;

        for ($i = 1; $i <= $count; $i++) {
            // The last row absorbs the division remainder so Σ principal_due == principal exactly.
            $principalDue = $i === $count ? $remaining : $base;
            $outstandingBefore = $remaining;
            $interestDue = self::periodInterest($outstandingBefore, $annualRatePercent, $frequency);

            $rows[] = [
                'seq' => $i,
                'due_on' => self::dueOn($firstPaymentOn, $frequency, $i)->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'total_due' => $principalDue + $interestDue,
            ];

            $remaining -= $principalDue;
        }

        return $rows;
    }

    /** Monthly: one twelfth of the annual rate on the outstanding balance; fortnightly: one twenty-sixth. */
    private static function periodInterest(int $outstandingMinor, string $annualRatePercent, LoanFrequency $frequency): int
    {
        // Integer minor units × percent × days-scale arithmetic done with bcmath-free integer
        // maths: outstanding * rate * 100 / (100 * periods) — the *100 keeps the percent integer
        // division honest before the divide.
        $rateBps = self::basisPoints($annualRatePercent);

        return intdiv($outstandingMinor * $rateBps, 10_000 * $frequency->periodsPerYear());
    }

    /** '12.5' => 1250 basis points; two decimals of a percent is all a lender quotes. */
    private static function basisPoints(string $annualRatePercent): int
    {
        return (int) round(((float) $annualRatePercent) * 100);
    }

    private static function dueOn(CarbonImmutable $first, LoanFrequency $frequency, int $seq): CarbonImmutable
    {
        return $frequency === LoanFrequency::Fortnightly
            ? $first->addDays(($seq - 1) * $frequency->stepDays())
            : $first->addMonths($seq - 1);
    }
}
