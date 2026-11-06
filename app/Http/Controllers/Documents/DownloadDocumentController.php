<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Domain\Kernel\Documents\Actions\DownloadDocument;
use App\Domain\Kernel\Documents\Exceptions\DocumentNotDownloadable;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download endpoint — the only route that reads the private disk.
 *
 * Three checks in a deliberate order, because each answers a different question and the cheap one
 * goes first:
 *
 *  - `signed` (on the route) asks *is this URL ours and still inside its TTL*;
 *  - the policy asks *is the person holding this URL allowed to read this document* — the signature
 *    proves the link was minted by the app, never that the browser holding it is the one it was
 *    minted for, so the session is authenticated and gated here rather than trusted because a
 *    signature was present;
 *  - the Action asks *may these bytes leave* (lifecycle, scan verdict, present on disk).
 *
 * It contains no business logic. The D1 says an HTTP entry point validates and calls one Action,
 * and this one has a single line of that: `DownloadDocument::handle`. Everything the download
 * promises is enforced in the domain, which is where the kiosk path and a future API surface will
 * need the same answers.
 */
final class DownloadDocumentController
{
    public function __invoke(DocumentVersion $documentVersion, DownloadDocument $download): StreamedResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        /** @var Document $document */
        $document = $documentVersion->document;

        if ($user === null || Gate::denies('download', $document)) {
            // Refused before the trail: a download attempt by somebody with no right to the paper is
            // a security question, not a document question, and audits the reads that happened.
            abort(403, 'Your role may not download this document.');
        }

        try {
            return $download->handle($documentVersion, $user->id);
        } catch (DocumentNotDownloadable $e) {
            // 410 rather than 404 for a refused file: the document exists, the caller may even be
            // allowed to know that it exists, and the reason the bytes did not arrive is a decision
            // with a stated cause. A 404 would tell them nothing and send them to a ticket.
            abort(410, $e->getMessage());
        }
    }
}
