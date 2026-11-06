<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

/**
 * A read-only projection of a chart row, for callers that must validate a code without touching
 * the ledger's models (D3). The cutover validator is the first such caller; Phase 5's COA screen
 * will be the next.
 *
 * `requiredDimensions` is carried because a postable account can still be wrong for an importer
 * that has no dimension columns — the ledger will refuse the line, and validation should say so
 * before the Owner presses post. `type` is carried because the statements read the account's
 * {@see AccountType::statement()} axis rather than re-deriving it from the code range.
 *
 * `parentCode` is carried because the balance sheet groups under the chart's own header rows
 * ("Current assets", "Fixed assets") and the cash-flow statement classifies through them —
 * both read the tree the accountant seeded, not code ranges.
 */
final class ChartAccount
{
    /**
     * @param  list<string>  $requiredDimensions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly AccountType $type,
        public readonly bool $isPostable,
        public readonly bool $isActive,
        public readonly array $requiredDimensions = [],
        public readonly ?string $parentCode = null,
    ) {}
}
