<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Exceptions;

use RuntimeException;

/**
 * A cutover request the domain refuses — always with the state it saw, so the screen can say why
 * the button did nothing instead of showing a generic failure.
 */
final class CutoverRejected extends RuntimeException
{
    public static function notReady(string $status): self
    {
        return new self("This batch is '{$status}' and cannot be posted. Only a clean, validated batch can post.");
    }

    public static function notPosted(string $status): self
    {
        return new self("This batch is '{$status}' and has nothing to reverse.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reversal needs a reason. It is stored on the operational event and on the reversing entry.');
    }

    public static function datasetUnsupported(string $dataset): self
    {
        return new self("The cutover engine has no validator for '{$dataset}'. Add one before storing its batches.");
    }

    public static function alreadyPosted(string $status): self
    {
        return new self("This batch is already '{$status}'. A posted opening balance is corrected by reversal, never re-posted.");
    }

    public static function notMasterData(string $dataset): self
    {
        return new self("A '{$dataset}' batch applies to the ledger, not to master data. Commit it from the opening-balance screen.");
    }

    public static function notOpeningBalances(string $dataset): self
    {
        return new self("A '{$dataset}' batch applies to master data and cannot be posted as a journal.");
    }
}
