<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover;

use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Providers\DomainServiceProvider;

/**
 * What a module must provide for one master-data dataset (Phase 1, D2).
 *
 * The Kernel owns the batch, the rows, the error report and the two-phase flow; a module owns what
 * a row means and how it becomes a record. The engine resolves an implementation from the registry
 * in {@see DomainServiceProvider}, so no file under `app/Domain/Kernel` names a module.
 *
 * `validate()` must be pure: it reads the existing records the rows will update, but it writes
 * nothing. `apply()` is called inside the batch's transaction and must be safe to retry — matching
 * by natural key is the contract, because a commit that dies after writing half its rows leaves
 * nothing behind and the same batch can be applied again.
 */
interface CutoverDatasetHandler
{
    public function validate(string $contents, string $companyCurrency): CutoverValidation;

    /** @return int the number of valid rows applied */
    public function apply(CutoverBatch $batch, int $userId): int;
}
