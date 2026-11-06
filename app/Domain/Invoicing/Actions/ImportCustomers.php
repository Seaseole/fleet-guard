<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use InvalidArgumentException;

/**
 * Phase 1 — the customer master file.
 *
 * A row is matched by code when it carries one, otherwise by name; both keys are unique per company,
 * which is why validation preloads them. The check is deliberately done here rather than left to the
 * two unique indexes, because a duplicate key arrives as a database exception on the last row of a
 * thousand and the error report would lose every row before it.
 *
 * Row values are written through {@see CreateCustomer}/{@see UpdateCustomer} exactly as the screen
 * does, so an imported customer cannot differ from a typed one.
 */
final class ImportCustomers implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateCustomer $create,
        private readonly UpdateCustomer $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $byCode = [];
        $byName = [];

        foreach (Customer::query()->get(['id', 'code', 'name']) as $customer) {
            if ($customer->code !== null && $customer->code !== '') {
                $byCode[ImportValues::codeKey($customer->code)] = $customer->id;
            }

            $byName[ImportValues::nameKey($customer->name)] = $customer->id;
        }

        $seenCodes = [];
        $seenNames = [];

        return CutoverRows::read(
            CutoverDataset::Customers,
            $contents,
            function (CutoverCells $cells) use ($byCode, $byName, &$seenCodes, &$seenNames) {
                $errors = [];

                [$name, $error] = ImportValues::text($cells->get('name'), 120, 'Name', true);
                ImportValues::push($errors, $error);

                [$code, $error] = ImportValues::text($cells->get('code'), 24, 'Code');
                ImportValues::push($errors, $error);

                [$contactName, $error] = ImportValues::text($cells->get('contact_name'), 80, 'Contact name');
                ImportValues::push($errors, $error);

                [$contactEmail, $error] = ImportValues::text($cells->get('contact_email'), 120, 'Contact email');
                ImportValues::push($errors, $error);

                [$contactPhone, $error] = ImportValues::text($cells->get('contact_phone'), 32, 'Contact phone');
                ImportValues::push($errors, $error);

                [$vatNumber, $error] = ImportValues::text($cells->get('vat_number'), 32, 'VAT number');
                ImportValues::push($errors, $error);

                [$paymentTerms, $error] = ImportValues::text(
                    $cells->get('payment_terms'),
                    24,
                    'Payment terms',
                );
                ImportValues::push($errors, $error);

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($name !== null) {
                    $nameKey = ImportValues::nameKey($name);

                    if (isset($seenNames[$nameKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the customer "%s".',
                            $seenNames[$nameKey],
                            $name,
                        );
                    } else {
                        $seenNames[$nameKey] = $cells->line;
                    }

                    $target = isset($byName[$nameKey]) ? $byName[$nameKey] : null;

                    if ($target !== null && ($code === null || ($byCode[ImportValues::codeKey($code)] ?? null) !== $target)) {
                        $errors[] = sprintf('Another customer is already named "%s" — its row is not the one this line updates.', $name);
                    }
                }

                if ($code !== null) {
                    $codeKey = ImportValues::codeKey($code);

                    if (isset($seenCodes[$codeKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the customer code "%s".',
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
                    'contact_name' => $contactName,
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'vat_number' => $vatNumber,
                    'payment_terms' => $paymentTerms === null ? null : strtolower($paymentTerms),
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        CustomerDto::fromArray($values);
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
            $dto = CustomerDto::fromArray($row->payload ?? []);
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

    private function match(CustomerDto $dto): ?Customer
    {
        if ($dto->code !== null) {
            $found = Customer::query()->where('code', $dto->code)->first();

            if ($found !== null) {
                return $found;
            }
        }

        return Customer::query()->where('name', $dto->name)->first();
    }
}
