<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit;

use RuntimeException;

/**
 * Someone or something tried to change a row in an audit trail.
 *
 * The ledger gets this treatment from four DB layers because a wrong posting is a wrong trial
 * balance; the trails get it from one model guard because their failure mode is different — a
 * quiet edit that makes an investigation report something that never happened.
 */
final class AuditTrailIsAppendOnly extends RuntimeException
{
    public static function writeAttempted(object $row, string $operation): self
    {
        return new self(sprintf(
            '%s of a %s row is not permitted: the audit trail is append-only. '
            .'A correction is another row that says what changed and why, never an edit of the row that was right at the time.',
            ucfirst($operation),
            class_basename($row),
        ));
    }
}
