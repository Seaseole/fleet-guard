<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\SupplierCategory;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use InvalidArgumentException;

/**
 * §15.2 Phase 1 — the supplier master file.
 *
 * The category defaults to `other` when the file leaves it blank, matching the DTO; a value outside
 * §4.2's six is a row error naming the accepted choices, not a CHECK violation at commit.
 */
final class ImportSuppliers implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateSupplier $create,
        private readonly UpdateSupplier $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $byCode = [];
        $byName = [];

        foreach (Supplier::query()->get(['id', 'code', 'name']) as $supplier) {
            if ($supplier->code !== null && $supplier->code !== '') {
                $byCode[ImportValues::codeKey($supplier->code)] = $supplier->id;
            }

            $byName[ImportValues::nameKey($supplier->name)] = $supplier->id;
        }

        $seenCodes = [];
        $seenNames = [];

        return CutoverRows::read(
            CutoverDataset::Suppliers,
            $contents,
            function (CutoverCells $cells) use ($byCode, $byName, &$seenCodes, &$seenNames) {
                $errors = [];

                [$name, $error] = ImportValues::text($cells->get('name'), 120, 'Name', true);
                ImportValues::push($errors, $error);

                [$code, $error] = ImportValues::text($cells->get('code'), 24, 'Code');
                ImportValues::push($errors, $error);

                [$categoryRaw, $error] = ImportValues::text($cells->get('category'), 16, 'Category');
                ImportValues::push($errors, $error);

                $category = null;

                if ($categoryRaw !== null) {
                    $category = SupplierCategory::tryFrom(strtolower($categoryRaw));

                    if ($category === null) {
                        $errors[] = sprintf(
                            'Category "%s" is not one of %s.',
                            $categoryRaw,
                            implode(', ', SupplierCategory::values()),
                        );
                    }
                }

                [$contactName, $error] = ImportValues::text($cells->get('contact_name'), 80, 'Contact name');
                ImportValues::push($errors, $error);

                [$contactEmail, $error] = ImportValues::text($cells->get('contact_email'), 120, 'Contact email');
                ImportValues::push($errors, $error);

                [$contactPhone, $error] = ImportValues::text($cells->get('contact_phone'), 32, 'Contact phone');
                ImportValues::push($errors, $error);

                [$vatNumber, $error] = ImportValues::text($cells->get('vat_number'), 32, 'VAT number');
                ImportValues::push($errors, $error);

                [$paymentTerms, $error] = ImportValues::text($cells->get('payment_terms'), 24, 'Payment terms');
                ImportValues::push($errors, $error);

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($name !== null) {
                    $nameKey = ImportValues::nameKey($name);

                    if (isset($seenNames[$nameKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the supplier "%s".',
                            $seenNames[$nameKey],
                            $name,
                        );
                    } else {
                        $seenNames[$nameKey] = $cells->line;
                    }

                    $target = $byName[$nameKey] ?? null;

                    if ($target !== null && ($code === null || ($byCode[ImportValues::codeKey($code)] ?? null) !== $target)) {
                        $errors[] = sprintf('Another supplier is already named "%s" — its row is not the one this line updates.', $name);
                    }
                }

                if ($code !== null) {
                    $codeKey = ImportValues::codeKey($code);

                    if (isset($seenCodes[$codeKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the supplier code "%s".',
                            $seenCodes[$codeKey],
                            $code,
                        );
                    } else {
                        $seenCodes[$codeKey] = $cells->line;
                    }
                }

                $values = [
                    'name' => $name,
                    'code' => $code,
                    'category' => $category?->value,
                    'contact_name' => $contactName,
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'vat_number' => $vatNumber,
                    'payment_terms' => $paymentTerms === null ? null : strtolower($paymentTerms),
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        SupplierDto::fromArray($values);
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
            $dto = SupplierDto::fromArray($row->payload ?? []);
            $existing = $this->match($dto);

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }

    private function match(SupplierDto $dto): ?Supplier
    {
        if ($dto->code !== null) {
            $found = Supplier::query()->where('code', $dto->code)->first();

            if ($found !== null) {
                return $found;
            }
        }

        return Supplier::query()->where('name', $dto->name)->first();
    }
}
