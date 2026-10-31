<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * §9.6's driver link, drawn as a code a phone camera reads at a gate.
 *
 * Bacon rather than the `exeque/laravel-qr` this repository also carries: every release of that package
 * declares `php ^8.5`, and its `clone($this, […])` syntax is a fatal parse error on the 8.4 runtime this
 * app runs on. Bacon is already here — Fortify draws the 2FA setup code with it — and answers the same
 * need without moving the runtime.
 *
 * SVG, not PNG, because it is the only shape Bacon draws on this box without Imagick, and it suits both
 * readers anyway: the screen gets it crisp at any zoom, and the clipboard at a border post prints it at
 * whatever size the paper asks for. A raster of a code that has to survive being folded in a shirt pocket
 * is a worse idea than a vector of it.
 *
 * Black on white, deliberately unstyled. The link is 48 hex characters and the panel it sits on is dark, so
 * anything softer than pure ink costs the driver a second attempt at the gate. Bacon paints the white ground
 * itself, which is what makes the markup safe to drop straight into a page.
 */
final class DriverLinkCode
{
    /** ISO 18004's minimum light margin, counted in modules rather than pixels. */
    private const QUIET_ZONE = 4;

    /**
     * The code as SVG markup, ready to print into a page or send as a file.
     *
     * The XML declaration is dropped because the same markup goes into a page — a file is identified by the
     * `Content-Type` its route sends, so nothing needs it back.
     */
    public static function svg(string $content, int $size = 208): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle($size, self::QUIET_ZONE, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
                new SvgImageBackEnd
            )
        ))->writeString($content, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());

        return trim(preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg) ?? $svg);
    }
}
