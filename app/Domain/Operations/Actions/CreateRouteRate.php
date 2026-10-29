<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Operations\Data\RouteRateDto;
use App\Domain\Operations\Exceptions\RouteRateOverlap;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\RouteRate;
use App\Domain\Operations\Support\RouteRateWindow;
use Illuminate\Support\Facades\DB;

/**
 * Files a price for a customer on a corridor (§4.1, D1).
 *
 * Four checks in order, each with its own failure: the route exists in this company, the customer
 * exists in this company, the currency is one the books know, and no live card already covers the
 * window. The first two go through the tenant scope, so a posted id from another company is a 404
 * rather than a cross-tenant row — the FK alone would happily accept it.
 *
 * @throws RouteRateOverlap when the window intersects an existing card for the same key
 */
final class CreateRouteRate
{
    public function __invoke(RouteRateDto $dto): RouteRate
    {
        return DB::transaction(function () use ($dto): RouteRate {
            $route = Route::query()->findOrFail($dto->routeId);
            $customer = Customer::query()->findOrFail($dto->customerId);

            Currency::of($dto->currencyCode);

            RouteRateWindow::assertFree(
                $route->id,
                $customer->id,
                $dto->rateType,
                $dto->validFrom,
                $dto->validTo,
            );

            $rate = new RouteRate([
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
