<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Support\LedgerAccountLink;
use Illuminate\Support\Facades\DB;

/**
 * Opens a bank, cash or wallet account (D1).
 *
 * The whole point of the Action is the ordering inside it: the plaintext number exists only in the
 * DTO, is converted to the trio by {@see BankAccount::changeNumber()}, and is never assigned to
 * an attribute that a validation error, an exception trace or a debug dump could print.
 */
final class CreateBankAccount
{
    public function __invoke(BankAccountDto $dto, ?LedgerAccountLink $links = null): BankAccount
    {
        // Verified before the row exists: a refusal at this level means no half-formed register
        // row with a header account linked to it. Method-injected so today's direct `new`
        // call sites keep working; the default resolves it from the container.
        $glAccountId = $dto->glAccountId === null ? null : ($links ??= app(LedgerAccountLink::class))->assertLinkable($dto->glAccountId);

        return DB::transaction(function () use ($dto, $glAccountId): BankAccount {
            $account = new BankAccount([
                'name' => $dto->name,
                'institution' => $dto->institution,
                'branch' => $dto->branch,
                'currency_code' => $dto->currencyCode,
                'type' => $dto->type,
                'opened_on' => $dto->openedOn,
                'active' => $dto->active,
                'notes' => $dto->notes,
                'gl_account_id' => $glAccountId,
            ]);

            if (! $dto->preservesMaskedNumber()) {
                $account->changeNumber($dto->accountNumber);
            }

            $account->save();

            return $account;
        });
    }
}
