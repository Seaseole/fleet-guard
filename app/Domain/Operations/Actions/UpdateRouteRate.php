<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Operations\Data\RouteRateDto;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\RouteRate;
use App\Domain\Operations\Support\RouteRateWindow;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a rate card (D1) — a wrong digit, a wrong basis, a window that was entered from
 * the wrong month.
 *
 * The overlap check re-runs **excluding this row**, which is what makes an in-place correction
 * possible at all: without the exclusion every save would collide with itself. Changing the amount
 * of a card a trip was already billed against is not blocked here, because before Phase 2 there is
 * nothing to bill against — when trips exist, closing the window and opening a new one becomes the
 * only honest path and this Action grows that rule with it.
 */
final class UpdateRouteRate
{
    public function __invoke(RouteRate $rate, RouteRateDto $dto): RouteRate
    {
        return DB::transaction(function () use ($rate, $dto): RouteRate {
            $route = Route::query()->findOrFail($dto->routeId);
            $customer = Customer::query()->findOrFail($dto->customerId);

            Currency::of($dto->currencyCode);

            RouteRateWindow::assertFree(
                $route->id,
                $customer->id,
                $dto->rateType,
                $dto->validFrom,
                $dto->validTo,
                $rate->id,
            );

            $rate->fill([
                'route_id' => $route->id,
                'customer_id' => $customer->id,
                'rate_type' => $dto->rateType,
                'rate_minor_units' => $dto->rateMinorUnits,
                'currency_code' => $dto->currencyCode,
                'valid_from' => $dto->validFrom,
                'valid_to' => $dto->validTo,
            ]);

            $rate->save();

            return $rate;
        });
    }
}
