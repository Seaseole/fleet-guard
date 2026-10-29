<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger;

use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\Dimension;
use App\Domain\Kernel\Ledger\Models\Account;

/**
 * The one read door onto the chart of accounts (D3's mirror image).
 *
 * Modules may not import `Account`, and the cutover validator needs account metadata to say
 * "code 1120 is a header" before anything is posted. This returns projections rather than models
 * so the answer cannot be written back through the caller.
 */
final class ChartOfAccounts
{
    /** @return array<string, ChartAccount> keyed by account code */
    public function byCode(): array
    {
        $accounts = [];

        foreach (Account::query()->get(['id', 'code', 'name', 'is_postable', 'is_active', 'allowed_dimension_mask']) as $account) {
            $accounts[$account->code] = new ChartAccount(
                id: (int) $account->id,
                code: $account->code,
                name: $account->name,
                isPostable: $account->is_postable,
                isActive: $account->is_active,
                requiredDimensions: array_map(
                    static fn (Dimension $dimension): string => match ($dimension) {
                        Dimension::Truck => 'truck',
                        Dimension::Trip => 'trip',
                        Dimension::Customer => 'customer',
                        Dimension::Driver => 'driver',
                        Dimension::CostCentre => 'cost centre',
                    },
                    $account->requiredDimensions(),
                ),
            );
        }

        return $accounts;
    }
}
