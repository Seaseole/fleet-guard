<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings;

use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Egough\LaravelSettings\Contracts\DefersSettingEvents;
use Egough\LaravelSettings\Contracts\SettingsRepository;
use Egough\LaravelSettings\Support\Caster;
use Illuminate\Support\Facades\DB;

/**
 * The one door to company-held settings (§14.2, T4).
 *
 * The package's `SettingsRepository` contract is implemented here over `companies.settings`
 * rather than its own global table: a setting without a company is the tenant-less store T4
 * forbids, and the JSON blob already exists on the tenant row. `settings()` — the package's
 * helper, Blade directives and console commands — therefore reads and writes this company's
 * values, and a queued job or command that has pinned a company gets that company's values.
 *
 * A key's *default* is `config('fleet.<key>')`. Config holds the shape and the starting value;
 * the row holds what this company chose. `get()` is the only read an application class needs.
 */
final class CompanySettings implements DefersSettingEvents, SettingsRepository
{
    public function __construct(private readonly CachedSettings $cache) {}

    /**
     * Stored value first, then `config/fleet.php`, then the caller's default.
     *
     * The fleet fallback is why a screen can offer "reset to default" without duplicating the
     * number: the default has exactly one definition.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->values();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return config('fleet.'.$key) ?? $default;
    }

    /** @return array<string, mixed> flattened `setting.key` => value */
    public function values(): array
    {
        return $this->cache->all($this->companyId());
    }

    /** @return array<string, array{value: mixed, type: string}> */
    public function all(): array
    {
        $rows = [];

        foreach ($this->values() as $key => $value) {
            $rows[$key] = ['value' => $value, 'type' => Caster::detectType($value)];
        }

        return $rows;
    }

    /**
     * `$type` is part of the package's contract and is deliberately unused: the blob keeps the
     * value as JSON, so its PHP type *is* its type. The package's console command has already
     * cast the string before it reaches here.
     */
    public function set(string $key, mixed $value, ?string $type = null): void
    {
        $this->mutate(function (array $settings) use ($key, $value): array {
            data_set($settings, $key, $value);

            return $settings;
        });
    }

    public function forget(string $key): void
    {
        $this->mutate(function (array $settings) use ($key): array {
            data_forget($settings, $key);

            return self::pruneEmpty($settings);
        });
    }

    public function afterCommit(callable $callback): void
    {
        $connection = (new Company)->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function mutate(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $company = $this->company();

            // Two Owners saving at once must not each write their own stale copy of the blob.
            // The lock serialises them; `refresh()` is what keeps the memoised instance every
            // other reader in this request holds consistent with what we are about to save.
            $company->newQuery()->whereKey($company->getKey())->lockForUpdate()->first();
            $company->refresh();

            $company->settings = $change($company->settings ?? []);
            $company->save();

            $this->cache->flush((int) $company->getKey());
        });
    }

    private function companyId(): int
    {
        return (int) $this->company()->getKey();
    }

    /**
     * `data_forget()` leaves the parent objects behind (`fuel => []`); a reset that leaves empty
     * branches in the tenant row makes the row read as configured when it is not.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function pruneEmpty(array $values): array
    {
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            // A non-empty list is a value (`default_buckets`); an object that prunes to nothing is
            // what `data_forget()` leaves behind, and it must not look like a configured branch.
            if ($value !== [] && array_is_list($value)) {
                continue;
            }

            $value = $value === [] ? [] : self::pruneEmpty($value);

            if ($value === []) {
                unset($values[$key]);

                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * T2's context is an authenticated user or a pin. A console command has neither, and
     * ADR-0003 makes the sole company the instance, so that one row is the honest answer;
     * `sole()` throws rather than picks if a second company ever exists.
     */
    private function company(): Company
    {
        return CurrentCompany::tryId() === null
            ? Company::query()->sole()
            : CurrentCompany::model();
    }
}
