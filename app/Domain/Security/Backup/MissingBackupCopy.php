<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use RuntimeException;

/**
 * There is nothing on the off-site disk to verify.
 *
 * Separate from {@see BackupCorrupt} because "the copy is damaged" and "there is no copy" send the
 * operator to different places: one is a storage incident to investigate, the other is a scheduler
 * that never ran or a disk name that changed under the deployment. Both alarm, but the message has to
 * say which, or the first quarter after a silent failure is spent reading the wrong logs.
 */
final class MissingBackupCopy extends RuntimeException {}
