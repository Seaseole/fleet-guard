<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

/**
 * The name the app calls itself: the company's own name when a company is in context, and the
 * deployment's `app.name` otherwise.
 *
 * The header, page titles and auth screens all render outside a company when they are the login
 * form or a queued mail, so this is deliberately non-throwing. A guest screen shows APP_NAME —
 * the same value it showed before the company row became editable — rather than a blank brand.
 */
final class SiteName
{
    public static function current(): string
    {
        if (CurrentCompany::tryId() !== null) {
            return CurrentCompany::model()->name;
        }

        return (string) config('app.name', 'Fleet Guard');
    }
}
