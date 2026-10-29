<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Edits a supplier's master details (§4.2, D1).
 *
 * Retiring a supplier is `active = false` on this path rather than a delete: bills already recorded
 * against them must keep a name to print.
 */
final class UpdateSupplier
{
    public function __invoke(Supplier $supplier, SupplierDto $dto): Supplier
    {
        return DB::transaction(function () use ($supplier, $dto): Supplier {
            $supplier->fill([
                'code' => $dto->code,
                'name' => $dto->name,
                'category' => $dto->category,
                'contact_name' => $dto->contactName,
                'contact_email' => $dto->contactEmail,
                'contact_phone' => $dto->contactPhone,
                'vat_number' => $dto->vatNumber,
                'payment_terms' => $dto->paymentTerms,
                'active' => $dto->active,
            ]);

            $supplier->save();

            return $supplier;
        });
    }
}
