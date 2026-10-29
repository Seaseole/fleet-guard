<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Changes bank-account details. §9.1 lists this as a re-authenticated action — the `reauth`
 * middleware is task #21 and the catalogue already marks `bank.manage` with ⚑, so the route is the
 * only piece that will still need to be added.
 *
 * An omitted or masked `account_number` leaves the stored secret untouched rather than blanking it:
 * §9.5's rule is that a masked value is a display form, not data, and a form that round-trips its
 * own mask must not be able to overwrite the real number with `••••4521`.
 */
final class UpdateBankAccount
{
    public function __invoke(BankAccount $account, BankAccountDto $dto): BankAccount
    {
        return DB::transaction(function () use ($account, $dto): BankAccount {
            $account->fill([
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
