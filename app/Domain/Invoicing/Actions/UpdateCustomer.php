<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Edits a customer's master details (D1).
 *
 * The payment term is written from the DTO like any other field; a caller that cannot read terms is
 * the *screen's* problem to solve before it builds the DTO (it carries the stored value forward),
 * because an Action that silently ignored a field would be a rule nobody can see.
 */
final class UpdateCustomer
{
    public function __invoke(Customer $customer, CustomerDto $dto): Customer
    {
        return DB::transaction(function () use ($customer, $dto): Customer {
            $customer->fill([
                'code' => $dto->code,
                'name' => $dto->name,
                'contact_name' => $dto->contactName,
                'contact_email' => $dto->contactEmail,
                'contact_phone' => $dto->contactPhone,
                'vat_number' => $dto->vatNumber,
                'payment_terms' => $dto->paymentTerms,
                'active' => $dto->active,
            ]);

            $customer->save();

            return $customer;
        });
    }
}
