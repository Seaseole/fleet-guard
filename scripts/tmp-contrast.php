<?php

declare(strict_types=1);

function lum(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = [];

    foreach ([$hex[0].$hex[1], $hex[2].$hex[3], $hex[4].$hex[5]] as $pair) {
        $v = hexdec($pair) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function ratio(string $a, string $b): float
{
    [$hi, $lo] = [max(lum($a), lum($b)), min(lum($a), lum($b))];

    return round(($hi + 0.05) / ($lo + 0.05), 2);
}

function row(string $label, string $fg, string $bg, float $floor = 4.5): void
{
    $r = ratio($fg, $bg);
    printf("  %-40s %5.2f:1 %s\n", $label, $r, $r >= $floor ? 'PASS' : 'FAIL');
}

echo "FINAL LIGHT\n";
row('ink/foreground on canvas', '#131417', '#fafafa');
row('ink on card', '#131417', '#ffffff');
row('ink on muted(sunk)', '#131417', '#f4f4f5');
row('ink-secondary on canvas', '#52555c', '#fafafa');
row('ink-secondary on card', '#52555c', '#ffffff');
row('ink-muted on canvas', '#6b6f77', '#fafafa');
row('ink-muted on card', '#6b6f77', '#ffffff');
row('ink-muted on sunk', '#6b6f77', '#f4f4f5');
row('on-primary on primary(teal)', '#ffffff', '#0f766e');
row('on-danger on danger', '#ffffff', '#b42318');
row('primary text on card', '#0f766e', '#ffffff');
row('primary text on canvas', '#0f766e', '#fafafa');
row('neutral-badge text on card', '#5a5f66', '#ffffff');
row('neutral-badge text on sunk', '#5a5f66', '#f4f4f5');
row('danger on danger-soft', '#b42318', '#fff1f0');
row('success on success-soft', '#067647', '#ecfdf3');
row('warning on warning-soft', '#b54708', '#fffaeb');
row('info on info-soft', '#175cd3', '#eff6ff');
row('canvas text on ink (tooltip)', '#131417', '#131417');
row('fg-canvas text on ink (tooltip)', '#fafafa', '#131417');
row('ring vs canvas', '#0f766e', '#fafafa', 3.0);
row('line-strong vs card (decorative)', '#c8c9ce', '#ffffff', 1.0);
row('line vs card (decorative)', '#e4e4e7', '#ffffff', 1.0);
row('hover surface vs card', '#f0f0f2', '#ffffff', 1.0);

echo "\nFINAL DARK\n";
row('ink on canvas', '#e7e9ea', '#0e1013');
row('ink on card', '#e7e9ea', '#171a1f');
row('ink on raised', '#e7e9ea', '#1d2127');
row('ink on sunk', '#e7e9ea', '#21252b');
row('ink-secondary on canvas', '#a8adb5', '#0e1013');
row('ink-secondary on card', '#a8adb5', '#171a1f');
row('ink-muted on canvas', '#90949d', '#0e1013');
row('ink-muted on card', '#90949d', '#171a1f');
row('ink-muted on sunk', '#90949d', '#21252b');
row('on-primary on primary', '#04211e', '#2dbbae');
row('on-danger on danger', '#0e1013', '#f97066');
row('primary text on card', '#2dbbae', '#171a1f');
row('neutral-badge text on card', '#99a0a8', '#171a1f');
row('danger on danger-soft', '#f97066', '#2c1413');
row('success on success-soft', '#4cc38a', '#0d2a1c');
row('warning on warning-soft', '#f79009', '#2a1c0a');
row('info on info-soft', '#7ca9f9', '#101d33');
row('canvas text on ink (tooltip)', '#0e1013', '#e7e9ea');
row('ring vs canvas', '#2dbbae', '#0e1013', 3.0);
row('line-strong vs card (decorative)', '#3a4048', '#171a1f', 1.0);
