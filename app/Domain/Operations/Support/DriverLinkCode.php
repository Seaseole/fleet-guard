<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Akira\QrCode\Facades\QrCode;

/**
 * The driver link, drawn as a code a phone camera reads at a gate.
 *
 * `akira/laravel-qrcode` over raw Bacon because it is the same renderer with the styling exposed — a
 * rounded module path, circle eyes, brand ink — which is what the code now wears. The version installed
 * is the one that asks only for `ext-gd`: this box has no Imagick in any of its PHP builds, so the
 * releases that require it for PNG output cannot be installed here at all. Only SVG is asked of it, which
 * is also the shape that survives both readers — crisp at any zoom on the screen, and at whatever size
 * the paper at a border post asks for from the download route.
 *
 * The ink is the brand's own green, and it is deliberately **not** the theme's accent: `--color-accent`
 * is #61d394 on the dark theme, a light green on a light ground that no camera decodes. So the figure is
 * pinned to the light palette (#118344, 4.8:1 against white) and the code always paints its own white
 * ground. Two things pay for the softer contrast, because a link that costs the driver a second attempt
 * at the gate is worse than a plain one: error correction rises from M to Q, which is the redundancy that
 * survives a folded shirt pocket, and the panel box grows to match — Q widens the matrix from 37 modules
 * to 45, so the old 10rem box would have rendered 2.7 CSS px per module against the 3.2 the plain code
 * managed, and 12rem keeps 3.3.
 */
final class DriverLinkCode
{
    /** ISO 18004's minimum light margin, counted in modules rather than pixels. */
    private const QUIET_ZONE = 4;

    /** `--color-accent` on the light theme, as the three channels Bacon wants. */
    private const INK = [17, 131, 68];

    /**
     * The code as SVG markup, ready to print into a page or send as a file.
     *
     * The XML declaration is dropped because the same markup goes into a page — a file is identified by the
     * `Content-Type` its route sends, so nothing needs it back.
     */
    public static function svg(string $content, int $size = 208): string
    {
        $svg = (string) QrCode::size($size)
            ->margin(self::QUIET_ZONE)
            // Asked for out loud rather than left to the class default: the package's own config file
            // prefers png, and png needs the Imagick this box has not got.
            ->format('svg')
            ->errorCorrection('Q')
            ->color(...self::INK)
            ->backgroundColor(255, 255, 255)
            ->style('round', 0.6)
            ->eye('circle')
            ->generate($content);

        return trim(preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg) ?? $svg);
    }
}
