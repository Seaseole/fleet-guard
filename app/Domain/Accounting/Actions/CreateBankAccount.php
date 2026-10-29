<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Opens a bank, cash or wallet account (§4.2, D1).
 *
 * The whole point of the Action is the ordering inside it: the plaintext number exists only in the
 * DTO, is converted to the §9.5 trio by {@see BankAccount::changeNumber()}, and is never assigned to
 * an attribute that a validation error, an exception trace or a debug dump could print.
 */
final class CreateBankAccount
{
    public function __invoke(BankAccountDto $dto): BankAccount
    {
        return DB::transaction(function () use ($dto): BankAccount {
            $account = new BankAccount([
                'name' => $dto->name,
                'institution' => $dto->institution,
                'branch' => $dto->branch,
                'currency_code' => $dto->currencyCode,
                'type' => $dto->type,
                'opened_on' => $dto->openedOn,
                'active' => $dto->active,
                'notes' => $dto->notes,
            ]);

            if (! $dto->preservesMaskedNumber()) {
                $account->changeNumber($dto->accountNumber);
            }

            $account->save();

            return $account;
        });
    }
}
