<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Kernel\Tenancy\SiteName;

/**
 * The theme the app opens with, before any script runs.
 *
 * The company's choice is the site-wide default (§20.37) so a fresh device — a phone handed to a
 * driver, a yard terminal, the Owner's laptop after a rebuild — renders the same way without anyone
 * configuring it. A personal choice stays per-browser, because it has to apply **before first paint**
 * and a server round-trip per page cannot do that; `partials/head.blade.php` reads one, then the
 * other, and never asks the network.
 *
 * The class is rendered server-side so a browser with JavaScript disabled still gets the company
 * theme. It resolves only what the server can know: `dark` needs the class, `light` and `system`
 * need none, and `system` is the head script's job because only the device knows its own preference.
 *
 * Deliberately non-throwing, like {@see SiteName}: the login screen and
 * invitation mail render with no company in context, and a blank page is not an acceptable answer to
 * "which theme?" — `system` is.
 */
final class Theme
{
    public const Light = 'light';

    public const Dark = 'dark';

    public const System = 'system';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::Light, self::Dark, self::System];
    }

    /** The company-wide default, or `system` when no company is in context or the value is unreadable. */
    public static function defaultMode(): string
    {
        if (CurrentCompany::tryId() === null) {
            return self::fallback();
        }

        $mode = strtolower(trim((string) app(CompanySettings::class)->get('appearance.theme', self::System)));

        return in_array($mode, self::modes(), true) ? $mode : self::fallback();
    }

    /** The class the root element carries on first paint. */
    public static function htmlClass(): ?string
    {
        return self::defaultMode() === self::Dark ? 'dark' : null;
    }

    private static function fallback(): string
    {
        $mode = strtolower((string) config('fleet.appearance.theme', self::System));

        return in_array($mode, self::modes(), true) ? $mode : self::System;
    }
}
