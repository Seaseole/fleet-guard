<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Scan;

/**
 * The adapter for an environment with no scanner — which is this development machine, and is what
 * Laravel Cloud is until §17 Q8 is answered.
 *
 * It says so, in the row: the scan detail records that the type allowlist is the substituted
 * control. That is the difference between a documented gap and a gap nobody knows about, and it is
 * the sentence an owner reads before deciding whether to pay for a hosted scanning API.
 */
final class NoScanner implements VirusScanner
{
    public function scan(string $absolutePath, string $hint): ScanResult
    {
        return ScanResult::pending('No scanner configured (fleet.documents.scanner = none); the per-type MIME allowlist is the substituted control (§14.4, §17 Q8).');
    }

    public function isAuthoritative(): bool
    {
        return false;
    }
}
