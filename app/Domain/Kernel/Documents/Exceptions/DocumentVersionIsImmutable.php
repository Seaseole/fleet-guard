<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Exceptions;

use App\Domain\Kernel\Documents\Models\DocumentVersion;
use RuntimeException;

/**
 * An update tried to change the bytes a version describes (§10.8's layer 1).
 *
 * The ledger gets the same treatment from four database layers because a wrong posting corrupts a
 * number; here one model guard is enough because the failure is not arithmetic but evidence —
 * someone editing a row until it describes different paper. The message names the columns because
 * the fix is always "write a new version", and a developer who can see which column they reached
 * for will see that.
 */
final class DocumentVersionIsImmutable extends RuntimeException
{
    /**
     * @param  list<string>  $columns
     */
    public static function columns(DocumentVersion $version, array $columns): self
    {
        return new self(sprintf(
            'Document version %d (document %s) is immutable — %s cannot be changed once the bytes are stored. '
            .'A correction is another version with the new bytes, never an edit of the row that describes the old ones (§10.8).',
            $version->version_seq,
            $version->document_id,
            implode(', ', $columns),
        ));
    }
}
