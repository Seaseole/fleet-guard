<?php

declare(strict_types=1);

/*
 * The contrast gate. Every colour pair the design system claims is AA-checked gets measured here, against
 * the token files themselves — not against hexes copied into a script.
 *
 * The reason this exists is `scripts/tmp-contrast.php`, which still checks `#131417` on `#fafafa`: the cool
 * zinc palette retired on 2026-11-05 and the checker went with it, silently. A hand-copied ratio is a
 * comment, and a comment about a value that moved is a wrong answer that reads like evidence. Reading the
 * tokens is the only way this stays true after a skin swap.
 *
 * Floors are WCAG's own two numbers: 4.5:1 for text, 3:1 for a non-text affordance that has to be
 * perceivable (the focus ring, a control's edge against its ground).
 *
 *   php scripts/contrast-table.php          measure both themes
 *   php scripts/contrast-table.php --light  one theme
 */

$root = dirname(__DIR__);

function tokens_from(string $file): array
{
    if (! is_file($file)) {
        fwrite(STDERR, "missing token file: {$file}\n");

        exit(2);
    }

    $css = file_get_contents($file);
    $out = [];

    foreach (explode("\n", $css) as $line) {
        if (preg_match('/^\s*(--color-[a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8}|rgb\([^)]*\))\s*;/', $line, $m)) {
            $out[$m[1]] = $m[2];
        }
    }

    return $out;
}

/** `#rgb`, `#rrggbb`, `#rrggbbaa` and `rgb(r g b / a)` all reduce to three 0-255 channels. */
function channels(string $colour): array
{
    $colour = trim($colour);

    if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $colour, $m)) {
        $hex = $m[1];

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    if (preg_match('/rgb\(\s*(\d+)\s+(\d+)\s+(\d+)/', $colour, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    fwrite(STDERR, "unparsable colour: {$colour}\n");

    exit(2);
}

function luminance(string $colour): float
{
    $linear = array_map(
        fn (int $v): float => ($s = $v / 255) <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4,
        channels($colour)
    );

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

function ratio(string $a, string $b): float
{
    [$l1, $l2] = [luminance($a), luminance($b)];
    [$hi, $lo] = [max($l1, $l2), min($l1, $l2)];

    return round(($hi + 0.05) / ($lo + 0.05), 2);
}

/*
 * The pairs. Each entry is a claim the design system makes about itself; `who` says where the reading
 * happens, so a failure names the surface a user is actually looking at.
 *
 * @var list<array{0: string, 1: string, 2: string, 3: float}> [label, foreground token, background token, floor]
 */
$pairs = [
    ['ink on canvas', '--color-fg-ink', '--color-fg-canvas', 4.5],
    ['ink on surface', '--color-fg-ink', '--color-fg-surface', 4.5],
    ['ink on raised', '--color-fg-ink', '--color-fg-surface-raised', 4.5],
    ['ink on sunk', '--color-fg-ink', '--color-fg-surface-sunk', 4.5],
    ['ink on hover', '--color-fg-ink', '--color-fg-surface-hover', 4.5],
    ['ink-secondary on canvas', '--color-fg-ink-secondary', '--color-fg-canvas', 4.5],
    ['ink-secondary on surface', '--color-fg-ink-secondary', '--color-fg-surface', 4.5],
    ['ink-secondary on raised', '--color-fg-ink-secondary', '--color-fg-surface-raised', 4.5],
    ['ink-secondary on sunk', '--color-fg-ink-secondary', '--color-fg-surface-sunk', 4.5],
    ['ink-secondary on hover', '--color-fg-ink-secondary', '--color-fg-surface-hover', 4.5],
    ['ink-muted on canvas', '--color-fg-ink-muted', '--color-fg-canvas', 4.5],
    ['ink-muted on surface', '--color-fg-ink-muted', '--color-fg-surface', 4.5],
    ['ink-muted on raised', '--color-fg-ink-muted', '--color-fg-surface-raised', 4.5],
    ['ink-muted on sunk', '--color-fg-ink-muted', '--color-fg-surface-sunk', 4.5],
    ['ink-muted on hover', '--color-fg-ink-muted', '--color-fg-surface-hover', 4.5],
    ['canvas on ink (inverted chip)', '--color-fg-canvas', '--color-fg-ink', 4.5],

    /* The washes a badge or a selected row is built out of: the ink that actually sits on them, not the
       colour that names them. These are the pairs a hand-written ratio comment never carried, which is how
       `ink-muted on hover` shipped at 4.35:1 through two palette changes. */
    ['ink on accent-soft', '--color-fg-ink', '--color-accent-soft', 4.5],
    ['ink-muted on accent-soft', '--color-fg-ink-muted', '--color-accent-soft', 4.5],
    ['accent-ink on accent-soft', '--color-accent-ink', '--color-accent-soft', 4.5],
    ['ink on danger-soft', '--color-fg-ink', '--color-danger-soft', 4.5],
    ['ink on warning-soft', '--color-fg-ink', '--color-warning-soft', 4.5],
    ['ink on success-soft', '--color-fg-ink', '--color-success-soft', 4.5],
    ['ink on info-soft', '--color-fg-ink', '--color-info-soft', 4.5],

    /* The accent: the affirmative control and the accent used as a reading. */
    ['on-accent on accent (filled button)', '--color-on-accent', '--color-accent', 4.5],
    ['on-accent on accent-hover', '--color-on-accent', '--color-accent-hover', 4.5],
    ['accent as ring on surface', '--color-accent', '--color-fg-surface', 3.0],
    ['accent as ring on canvas', '--color-accent', '--color-fg-canvas', 3.0],
    ['accent as ring on sunk', '--color-accent', '--color-fg-surface-sunk', 3.0],
    ['accent-ink as text on surface', '--color-accent-ink', '--color-fg-surface', 4.5],
    ['accent-ink as text on canvas', '--color-accent-ink', '--color-fg-canvas', 4.5],
    ['accent-ink as text on sunk', '--color-accent-ink', '--color-fg-surface-sunk', 4.5],
    ['accent-ink as text on hover', '--color-accent-ink', '--color-fg-surface-hover', 4.5],
    ['accent-ink as display on surface', '--color-accent-ink', '--color-fg-surface', 4.5],
    ['accent-ink as display on canvas', '--color-accent-ink', '--color-fg-canvas', 4.5],
    ['accent-ink as display on sunk', '--color-accent-ink', '--color-fg-surface-sunk', 4.5],

    /* Semantic state, each on its own soft ground and on the surfaces it can land on. */
    ['success on success-soft', '--color-success', '--color-success-soft', 4.5],
    ['success on surface', '--color-success', '--color-fg-surface', 4.5],
    ['warning on warning-soft', '--color-warning', '--color-warning-soft', 4.5],
    ['warning on surface', '--color-warning', '--color-fg-surface', 4.5],
    ['danger on danger-soft', '--color-danger', '--color-danger-soft', 4.5],
    ['danger on surface', '--color-danger', '--color-fg-surface', 4.5],
    ['on-danger on danger (confirm step)', '--color-on-danger', '--color-danger', 4.5],
    ['info on info-soft', '--color-info', '--color-info-soft', 4.5],
    ['info on surface', '--color-info', '--color-fg-surface', 4.5],
    ['neutral badge on surface', '--color-neutral-badge', '--color-fg-surface', 4.5],
    ['neutral badge on sunk', '--color-neutral-badge', '--color-fg-surface-sunk', 4.5],

    /* Non-text: the affordance that has to be perceivable, not the text it sits beside. */
    ['focus ring on canvas', '--color-focus', '--color-fg-canvas', 3.0],
    ['focus ring on surface', '--color-focus', '--color-fg-surface', 3.0],
    ['focus ring on sunk', '--color-focus', '--color-fg-surface-sunk', 3.0],

    ['focus ring on raised', '--color-focus', '--color-fg-surface-raised', 3.0],
    ['focus ring on hover', '--color-focus', '--color-fg-surface-hover', 3.0],
    ['focus ring on accent-soft', '--color-focus', '--color-accent-soft', 3.0],

    /* Decorative separators: reported, never gated. A hairline is not the signal that a control exists. */
    ['line on surface (decorative)', '--color-fg-line', '--color-fg-surface', 1.0],
    ['line-strong on surface (decorative)', '--color-fg-line-strong', '--color-fg-surface', 1.0],
    ['line on canvas (decorative)', '--color-fg-line', '--color-fg-canvas', 1.0],
];

$themes = in_array('--light', $argv, true)
    ? ['light']
    : (in_array('--dark', $argv, true) ? ['dark'] : ['light', 'dark']);

$failures = 0;
$checked = 0;

foreach ($themes as $theme) {
    $palette = tokens_from($root.'/resources/css/tokens/palette.css');

    if ($theme === 'dark') {
        $palette = array_merge($palette, tokens_from($root.'/resources/css/tokens/themes.css'));
    }

    echo "\n".strtoupper($theme).' THEME — resources/css/tokens/'.($theme === 'dark' ? 'themes.css' : 'palette.css')."\n";

    foreach ($pairs as [$label, $fgName, $bgName, $floor]) {
        foreach ([$fgName, $bgName] as $name) {
            if (! isset($palette[$name])) {
                fwrite(STDERR, "  FAIL  {$label}: token {$name} is not defined in {$theme}\n");
                $failures++;

                continue 2;
            }
        }

        $r = ratio($palette[$fgName], $palette[$bgName]);
        $ok = $r >= $floor;
        $checked++;

        if (! $ok) {
            $failures++;
        }

        printf(
            "  %s %-40s %5.2f:1  (floor %.1f)  %s on %s\n",
            $ok ? 'ok  ' : 'FAIL',
            $label,
            $r,
            $floor,
            $palette[$fgName],
            $palette[$bgName]
        );
    }
}

printf(
    "\n%s: %d pairs, %d failure(s)\n",
    $failures === 0 ? 'CONTRAST OK' : 'CONTRAST FAIL',
    $checked,
    $failures
);

exit($failures === 0 ? 0 : 1);
