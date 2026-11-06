<?php

/*
 * `egough/laravel-settings` configuration.
 *
 * Storage is NOT the package's global `settings` table: T4 and DESIGN.md the design make every
 * setting company-scoped, so `App\Domain\Kernel\Settings\CompanySettings` implements the
 * package's `SettingsRepository` contract over the `companies.settings` JSON blob (one
 * memoised read per request, defaults in `config/fleet.php`). The table names below are
 * therefore unused by this application; they stay because they are the package's defaults.
 *
 * The package's own cache is disabled on purpose. Its key is a single static string, so a
 * company-scoped repository behind it would serve company A's blob to company B for the TTL —
 * the tenancy leak T2 exists to make impossible. `CachedSettings` caches per company instead
 * and flushes on write.
 *
 * `defaults` is left empty for the same reason: the defaults are `config/fleet.php`, read by
 * `CompanySettings::get()` when the company has no override, so there is one place to change
 * a starting value rather than two that can disagree.
 */

return [

    'table' => 'settings',

    'cache' => [
        'enabled' => false,
        'key' => 'egough.settings.all',
        'ttl' => 60,
    ],

    'defaults' => [
        //
    ],

    'model' => [
        'table' => 'model_settings',
        'cache' => [
            'enabled' => true,
            'ttl' => 3600,
            'key_prefix' => 'egough.model_settings.',
        ],
    ],

];
