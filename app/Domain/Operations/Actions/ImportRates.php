<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Operations\Data\RouteRateDto;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\RouteRate;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\Support\RouteRateWindow;
use InvalidArgumentException;

/**
 * Phase 1 — the agreed rate card.
 *
 * A row names its corridor and customer by code, because those codes are what the legacy export
 * carries; the ids are resolved here and again at apply, through the tenant scope.
 *
 * The overlap rule is the same one {@see RouteRateWindow::assertFree()} enforces for the typed form,
 * applied against two sets: the cards already in the database, and the rows earlier in the same file
 * that were accepted. Without the second set a file could write two live prices for one lane and the
 * database would accept both.
 */
final class ImportRates implements CutoverDatasetHandler
{
    public function __construct(private readonly CreateRouteRate $create) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $routes = [];
        $customers = [];

        foreach (Route::query()->get(['id', 'code']) as $route) {
            $routes[ImportValues::codeKey($route->code)] = $route->id;
        }

        foreach (Customer::query()->get(['id', 'code']) as $customer) {
            if ($customer->code !== null && $customer->code !== '') {
                $customers[ImportValues::codeKey($customer->code)] = $customer->id;
            }
        }

        $windows = [];

        foreach (RouteRate::query()->get(['route_id', 'customer_id', 'rate_type', 'valid_from', 'valid_to']) as $rate) {
            $windows[self::windowKey($rate->route_id, $rate->customer_id, $rate->rate_type->value)][] = [
                'from' => $rate->valid_from->toDateString(),
                'to' => $rate->valid_to?->toDateString(),
            ];
        }

        return CutoverRows::read(
            CutoverDataset::Rates,
            $contents,
            function (CutoverCells $cells) use ($routes, $customers, $companyCurrency, &$windows) {
                $errors = [];

                [$routeCode, $error] = ImportValues::code($cells->get('route_code'), 24, 'Route code', true);
                ImportValues::push($errors, $error);

                [$customerCode, $error] = ImportValues::text($cells->get('customer_code'), 24, 'Customer code', true);
                ImportValues::push($errors, $error);

                [$rateTypeRaw, $error] = ImportValues::text($cells->get('rate_type'), 12, 'Rate type', true);
                ImportValues::push($errors, $error);

                $rateType = null;

                if ($rateTypeRaw !== null) {
                    $rateType = RouteRateType::tryFrom(strtolower(str_replace([' ', '-'], '_', $rateTypeRaw)));

                    if ($rateType === null) {
                        $errors[] = sprintf(
                            'Rate type "%s" is not one of %s.',
                            $rateTypeRaw,
                            implode(', ', RouteRateType::values()),
                        );
                    }
                }

                [$currencyCode, $error] = ImportValues::code($cells->get('currency_code'), 3, 'Currency', true);
                ImportValues::push($errors, $error);

                [$rateAmount, $error] = ImportValues::money($cells->get('rate_amount'), $currencyCode ?? $companyCurrency, 'Rate amount');
                ImportValues::push($errors, $error);

                [$validFrom, $error] = ImportValues::date($cells->get('valid_from'), 'Valid from', true);
                ImportValues::push($errors, $error);

                [$validTo, $error] = ImportValues::date($cells->get('valid_to'), 'Valid to');
                ImportValues::push($errors, $error);

                if ($currencyCode !== null) {
                    try {
                        Currency::of($currencyCode);
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = "Currency: {$exception->getMessage()}";
                    }
                }

                $routeId = $routeCode === null ? null : ($routes[$routeCode] ?? null);

                if ($routeCode !== null && $routeId === null) {
                    $errors[] = sprintf('There is no route "%s".', $routeCode);
                }

                $customerKey = $customerCode === null ? null : ImportValues::codeKey($customerCode);
                $customerId = $customerKey === null ? null : ($customers[$customerKey] ?? null);

                if ($customerCode !== null && $customerId === null) {
                    $errors[] = sprintf('There is no customer with code "%s".', $customerCode);
                }

                if ($errors === [] && $routeId !== null && $customerId !== null && $rateType !== null && $validFrom !== null) {
                    $key = self::windowKey($routeId, $customerId, $rateType->value);

                    foreach ($windows[$key] ?? [] as $window) {
                        if (RouteRateWindow::overlaps($validFrom, $validTo, $window['from'], $window['to'])) {
                            $errors[] = sprintf(
                                'This window overlaps the card valid %s to %s.',
                                $window['from'],
                                $window['to'] ?? 'open-ended',
                            );
                        }
                    }

                    if ($errors === []) {
                        $windows[$key][] = ['from' => $validFrom, 'to' => $validTo];
                    }
                }

                $values = [
                    'route_code' => $routeCode,
                    'customer_code' => $customerCode,
                    'rate_type' => $rateType?->value,
                    'rate_amount' => $rateAmount,
                    'currency_code' => $currencyCode,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ];

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];

            $route = Route::query()->where('code', (string) ($payload['route_code'] ?? ''))->firstOrFail();
            $customer = Customer::query()->where('code', (string) ($payload['customer_code'] ?? ''))->firstOrFail();

            ($this->create)(RouteRateDto::fromArray([
                'route_id' => $route->id,
                'customer_id' => $customer->id,
                'rate_type' => $payload['rate_type'] ?? '',
                'rate_minor_units' => $payload['rate_amount'] ?? 0,
                'currency_code' => $payload['currency_code'] ?? $batch->currency_code,
                'valid_from' => $payload['valid_from'] ?? '',
                'valid_to' => $payload['valid_to'] ?? null,
            ]));

            $applied++;
        }

        return $applied;
    }

    private static function windowKey(int $routeId, int $customerId, string $rateType): string
    {
        return $routeId.':'.$customerId.':'.$rateType;
    }
}
