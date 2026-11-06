<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Documents\Exceptions\DocumentNotDownloadable;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use Illuminate\Support\Facades\URL;

/**
 * The short-lived signed download URL.
 *
 * The TTL is the control, and there is deliberately no token store behind it. A redemption table
 * would buy one thing — revoking a link nobody has used yet — at the cost of a row per click, a
 * cleanup job, and a second path through the download gate that has to stay correct forever. Sixty
 * seconds means a leaked URL works for less time than it takes to paste into a chat message, which
 * at 21 trucks is the whole threat model.
 *
 * `temporarySignedRoute` signs the URL, so what this returns is a bearer link: whoever holds it
 * inside the window is the actor. That is why the controller does not treat the signature as the
 * authorisation — it authenticates the session, applies the policy, and *then* serves — and why the
 * audit row records the person who was authorised, not merely the address that arrived.
 */
final class DocumentLink
{
    public const RouteName = 'documents.download';

    /**
     * @param  int|null  $ttlSeconds  an override for a link that must survive a slow rural connection
     *
     * @throws DocumentNotDownloadable when the copy could not be served anyway, so a screen is never
     *                                 handed a URL that is guaranteed to fail — a dead link that is clicked is a dead link
     *                                 that is audited.
     */
    public function temporaryFor(DocumentVersion $version, ?int $ttlSeconds = null): string
    {
        $this->assertServable($version);

        /** @var int $ttl */
        $ttl = $ttlSeconds ?? (int) config('fleet.documents.signed_url_ttl_seconds', 60);

        /** @var string $url */
        $url = URL::temporarySignedRoute(
            self::RouteName,
            now()->addSeconds(max(5, $ttl)),
            // The parameter name is the route's binding name, `{documentVersion}`. A mismatch here
            // does not fail until somebody clicks Download, and then it fails as a
            // UrlGenerationException inside the screen rather than as a refused file — so it is
            // spelled out and asserted in scripts/verify-documents.php.
            ['documentVersion' => $version->id],
        );

        return $url;
    }

    public function ttlSeconds(): int
    {
        return (int) config('fleet.documents.signed_url_ttl_seconds', 60);
    }

    /**
     * Two verdicts refuse, and they are different sentences: infected means the bytes are dangerous,
     * unscanned means nobody has said they are not. Telling them apart is what makes Q8 an
     * open question with a visible answer instead of a mystery error.
     */
    private function assertServable(DocumentVersion $version): void
    {
        if ($version->virus_scan_status === ScanVerdict::Infected) {
            throw DocumentNotDownloadable::infected($version);
        }

        if (! $version->isServable()) {
            throw DocumentNotDownloadable::unscanned();
        }
    }
}
