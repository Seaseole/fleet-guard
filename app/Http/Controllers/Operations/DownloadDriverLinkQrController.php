<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\DriverLinkCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The driver link, drawn as a code the gate or the pump can scan.
 *
 * A route rather than a Livewire action because the answer is a file — a printed dispatch sheet needs a URL
 * a browser can name — and because drawing it here keeps the bytes out of the snapshot that travels with
 * every Livewire request.
 *
 * What it draws comes from the session, which is the only place the secret still exists: the row keeps
 * `hash('sha256', …)` (TRIP_ACCESS_TOKEN), so a dismissed reveal is a 404 rather than a second door.
 * The code carries the link alone. The PIN stays with the dispatcher, because the design asks for it on every
 * submission — a code that contained it would turn "the phone is in the driver's hand" into "whoever
 * photographed the screen has it".
 */
final class DownloadDriverLinkQrController
{
    public function __invoke(Trip $trip): StreamedResponse
    {
        $reveal = session()->get('kiosk.reveal.'.$trip->id);

        if (! is_array($reveal) || ! isset($reveal['url'])) {
            abort(404, 'That link was shown once and is no longer in this session. Issue a new one from the trip.');
        }

        $svg = DriverLinkCode::svg((string) $reveal['url'], 1024);

        return response()->streamDownload(
            function () use ($svg): void {
                echo $svg;
            },
            'driver-link-'.$trip->trip_no.'.svg',
            [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                // A same-origin SVG is markup a browser will run if it is opened rather than printed. This one
                // draws nothing and links nowhere, so the policy says so instead of trusting that it stays so.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            ],
        );
    }
}
