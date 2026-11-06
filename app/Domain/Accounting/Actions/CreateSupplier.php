<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Adds a supplier to the books (D1).
 *
 * The category is written from the enum the DTO carries, so the string in the column is always one
 * of the six — a caller cannot post `category => 'tyres'` and meet the CHECK constraint at
 * commit with a message that names a column instead of a choice.
 */
final class CreateSupplier
{
    public function __invoke(SupplierDto $dto): Supplier
    {
        return DB::transaction(function () use ($dto): Supplier {
            $supplier = new Supplier([
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
