<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Scan;

/**
 * The virus scan step, as the seam rather than the answer.
 *
 * The design doc is explicit that Q8 is open: on Linux with a sidecar the honest adapter is
 * ClamAV over INSTREAM, and on Laravel Cloud there is no local daemon, so the real choices are a
 * hosted scanning API or documented defence-in-depth. Making the *step* an interface is what keeps
 * that an environment decision instead of a code rewrite — the caller is the upload Action and it
 * never learns which answer it got.
 *
 * The contract's important half is the failure mode: an adapter that cannot reach its scanner
 * returns **pending**, never clean and never an exception. A transport error reported as "clean" is
 * the worst thing a scanner can do, because the download gate then waves through exactly the files
 * nobody looked at.
 */
interface VirusScanner
{
    /**
     * @param  string  $absolutePath  a file that exists on the local filesystem at call time
     * @param  string  $hint  the name to report in the scanner's own logs
     */
    public function scan(string $absolutePath, string $hint): ScanResult;

    /** Whether this adapter actually judges bytes — the download gate asks it before it refuses. */
    public function isAuthoritative(): bool;
}
