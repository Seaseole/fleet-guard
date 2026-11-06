<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Settings\SettingsCatalogue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only write path for company settings.
 *
 * It refuses a key that is not in the catalogue, so "settings" cannot become a second, untyped
 * key/value store; it normalises at this boundary (a major-unit amount becomes minor units once);
 * and it records the change in the security trail, because marks `settings.manage` ⚑ and a
 * lowered approval limit is exactly the kind of "who did that" the trail exists for.
 */
final class UpdateCompanySettings
{
    public function __construct(
        private readonly CompanySettings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  validated form values keyed by setting key
     */
    public function __invoke(array $values): void
    {
        $unknown = array_diff(array_keys($values), SettingsCatalogue::keys());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Refusing to write settings outside the catalogue: '.implode(', ', $unknown));
        }

        // A door field's truth lives in another register, so storing it here would create the
        // second answer this design refuses: the form excludes them, but the rule belongs to the writer,
        // which is the only path every caller shares.
        $doors = array_values(array_filter(
            array_keys($values),
            static fn (string $key): bool => SettingsCatalogue::store($key) === 'door',
        ));

        if ($doors !== []) {
            throw new InvalidArgumentException(
                'Refusing to write these to the settings blob — their truth is another register, and a copy here would be a second answer: '
                .implode(', ', $doors),
            );
        }

        $changes = [];

        DB::transaction(function () use ($values, &$changes): void {
            foreach ($values as $key => $value) {
                $normalised = SettingsCatalogue::normalise($key, $value);
                $before = $this->settings->get($key);

                // Loose on purpose: MySQL's JSON column returns a stored `11.0` as int 11, so a
                // strict compare would log a "change" and an audit row every time the field is
                // saved untouched.
                if ($before == $normalised) {
                    continue;
                }

                $this->settings->set($key, $normalised);
                $changes[$key] = ['from' => $before, 'to' => $normalised];
            }
        });

        if ($changes === []) {
            return;
        }

        $this->audit->security(SecurityEventKind::SettingsChanged, ['changed' => $changes]);
    }
}
