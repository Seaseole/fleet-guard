<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Kernel\Ledger\Data\ChartAccount;

/**
 * Which section of the cash-flow statement a counterparty account belongs to.
 *
 * The classification walks the chart's own seeded tree: an account reports under the section
 * its header row lives in — receipts and payments against Current assets (AR, driver advances,
 * prepaid permits, stock) and Current liabilities (AP, VAT, unapplied cash) are *operating*,
 * movement against Fixed assets is *investing*, and against non-current liabilities or Equity
 * is *financing*. Revenue and expense accounts are operating by definition. The header codes
 * are `is_system` rows of COA v1, so this names the accountant's structure rather than
 * guessing code ranges — and an account the map does not recognise falls to operating, the
 * section the fleet's money overwhelmingly moves through, keeping the statement's totals whole
 * even when a custom header is added before its mapping is.
 */
final class CashFlowSections
{
    public const OPERATING = 'operating';

    public const INVESTING = 'investing';

    public const FINANCING = 'financing';

    /** Seeded COA v1 header code → section key; no annotation, the literal shape is the truth. */
    private const HEADER_SECTIONS = [
        '1100' => self::OPERATING,
        '1200' => self::INVESTING,
        '2100' => self::OPERATING,
        '2200' => self::FINANCING,
        '3000' => self::FINANCING,
    ];

    /** @var array<string, string> section key → printed heading */
    public const HEADINGS = [
        self::OPERATING => 'Operating',
        self::INVESTING => 'Investing',
        self::FINANCING => 'Financing',
    ];

    /** @param array<string, ChartAccount> $byCode */
    public static function classify(ChartAccount $account, array $byCode): string
    {
        if ($account->type->statement() === 'profit_and_loss') {
            return self::OPERATING;
        }

        // Walk up the tree through parent codes. The depth guard answers a broken cycle in the
        // chart (a hand-edited parent_id) with a section rather than a hung request.
        $code = $account->parentCode;

        for ($depth = 0; $code !== null && $depth < 10; $depth++) {
            if (isset(self::HEADER_SECTIONS[$code])) {
                return self::HEADER_SECTIONS[$code];
            }

            $code = $byCode[$code]->parentCode ?? null;
        }

        return self::OPERATING;
    }

    /** @return list<string> the section keys, in statement order */
    public static function order(): array
    {
        return [self::OPERATING, self::INVESTING, self::FINANCING];
    }
}
