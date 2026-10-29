<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Models\Part;
use App\Domain\Maintenance\PartCategory;
use InvalidArgumentException;

/**
 * §15.2 Phase 1 — the parts catalogue.
 *
 * A part's cost is in its own currency (the company's base currency when the file leaves the column
 * blank), because a store that buys from a South African factor quotes in ZAR. The optional truck is
 * named by registration and resolved inside the tenant scope, which is the same rule
 * {@see CreatePart} applies to a typed id.
 */
final class ImportParts implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreatePart $create,
        private readonly UpdatePart $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $trucks = [];

        foreach (Truck::query()->get(['registration_no']) as $truck) {
            $trucks[ImportValues::codeKey($truck->registration_no)] = $truck->registration_no;
        }

        $seenSkus = [];

        return CutoverRows::read(
            CutoverDataset::Parts,
            $contents,
            function (CutoverCells $cells) use ($trucks, $companyCurrency, &$seenSkus) {
                $errors = [];

                [$sku, $error] = ImportValues::code($cells->get('sku'), 32, 'SKU', true);
                ImportValues::push($errors, $error);

                [$name, $error] = ImportValues::text($cells->get('name'), 120, 'Name', true);
                ImportValues::push($errors, $error);

                [$categoryRaw, $error] = ImportValues::text($cells->get('category'), 24, 'Category');
                ImportValues::push($errors, $error);

                $category = PartCategory::Other;

                if ($categoryRaw !== null) {
                    $parsedCategory = PartCategory::tryFrom(strtolower($categoryRaw));

                    if ($parsedCategory === null) {
                        $errors[] = sprintf(
                            'Category "%s" is not one of %s.',
                            $categoryRaw,
                            implode(', ', PartCategory::values()),
                        );
                    } else {
                        $category = $parsedCategory;
                    }
                }

                [$currencyCode, $error] = ImportValues::code($cells->get('currency_code'), 3, 'Currency');
                ImportValues::push($errors, $error);

                $currency = $currencyCode ?? $companyCurrency;

                try {
                    Currency::of($currency);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = "Currency: {$exception->getMessage()}";
                }

                [$unitCost, $error] = ImportValues::money($cells->get('unit_cost'), $currency, 'Unit cost');
                ImportValues::push($errors, $error);

                [$reorderLevel, $error] = ImportValues::count($cells->get('reorder_level'), 'Reorder level');
                ImportValues::push($errors, $error);

                [$truckRegistration, $error] = ImportValues::text($cells->get('truck_registration_no'), 24, 'Truck registration');
                ImportValues::push($errors, $error);

                $truckRegistrationNo = null;

                if ($truckRegistration !== null) {
                    $truckKey = ImportValues::codeKey($truckRegistration);
                    $truckRegistrationNo = $trucks[$truckKey] ?? null;

                    if ($truckRegistrationNo === null) {
                        $errors[] = sprintf('There is no truck "%s".', $truckRegistration);
                    }
                }

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($sku !== null) {
                    if (isset($seenSkus[$sku])) {
                        $errors[] = sprintf('Line %d already carries the SKU "%s".', $seenSkus[$sku], $sku);
                    } else {
                        $seenSkus[$sku] = $cells->line;
                    }
                }

                $values = [
                    'sku' => $sku,
                    'name' => $name,
                    'category' => $category->value,
                    'unit_cost' => $unitCost,
                    'currency_code' => $currency,
                    'reorder_level' => $reorderLevel,
                    'truck_registration_no' => $truckRegistrationNo,
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        PartDto::fromArray(self::dtoInput($values, null));
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $truckId = null;

            if (($payload['truck_registration_no'] ?? null) !== null) {
                $truckId = Truck::query()
                    ->where('registration_no', (string) $payload['truck_registration_no'])
                    ->firstOrFail()
                    ->id;
            }

            $dto = PartDto::fromArray(self::dtoInput($payload, $truckId));
            $existing = Part::query()->where('sku', $dto->sku)->first();

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function dtoInput(array $payload, ?int $truckId): array
    {
        return [
            'sku' => $payload['sku'] ?? '',
            'name' => $payload['name'] ?? '',
            'category' => $payload['category'] ?? PartCategory::Other->value,
            'unit_cost_minor' => $payload['unit_cost'] ?? 0,
            'currency_code' => $payload['currency_code'] ?? '',
            'reorder_level' => $payload['reorder_level'] ?? null,
            'truck_id' => $truckId,
            'active' => $payload['active'] ?? true,
        ];
    }
}
