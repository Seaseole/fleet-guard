<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The identity an Owner may correct: the trading/site name, the registration number and the VAT
 * number. They are columns on the tenant root rather than keys in its settings blob, so this is a
 * second write path beside {@see UpdateCompanySettings} — same gate, same audit kind.
 *
 * The columns this action does *not* offer are the ones that define storage: base currency,
 * timezone, country and the fiscal-year start drive money scaling, date boundaries and numbering
 * periods. Changing one after documents exist would silently re-read history under a new rule, so
 * the screen shows them and says so rather than accepting an edit.
 */
final class UpdateCompanyProfile
{
    /** @var list<string> */
    private const COLUMNS = ['name', 'registration_number', 'vat_number'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $values  validated form values keyed by column
     */
    public function __invoke(array $values): void
    {
        $unknown = array_diff(array_keys($values), self::COLUMNS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Refusing to write company columns outside the profile: '.implode(', ', $unknown));
        }

        $changes = [];

        DB::transaction(function () use ($values, &$changes): void {
            $company = CurrentCompany::model();
            $company->newQuery()->whereKey($company->getKey())->lockForUpdate()->first();
            $company->refresh();

            foreach (self::COLUMNS as $column) {
                if (! array_key_exists($column, $values)) {
                    continue;
                }

                $after = $this->clean($column, $values[$column]);

                if ($company->{$column} === $after) {
                    continue;
                }

                $changes['company.'.$column] = ['from' => $company->{$column}, 'to' => $after];
                $company->{$column} = $after;
            }

            if ($changes !== []) {
                $company->save();
            }
        });

        if ($changes === []) {
            return;
        }

        $this->audit->security(SecurityEventKind::SettingsChanged, ['changed' => $changes]);
    }

    private function clean(string $column, mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($column === 'name') {
            return $text === '' ? throw new InvalidArgumentException('A company must have a name.') : $text;
        }

        return $text === '' ? null : $text;
    }
}
