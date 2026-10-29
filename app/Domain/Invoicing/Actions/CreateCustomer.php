<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Adds a customer to the books (§4.1, D1).
 *
 * There is no secret to stage here, so the Action is the thin write it looks like: the DTO has
 * already refused a malformed term and the page has checked the two per-company unique keys. What
 * the Action adds is the transaction and a single write path the future API shares.
 */
final class CreateCustomer
{
    public function __invoke(CustomerDto $dto): Customer
    {
        return DB::transaction(function () use ($dto): Customer {
            $customer = new Customer([
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
