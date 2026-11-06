<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings;

use App\Domain\Kernel\Tenancy\Company;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The read side: the company's settings blob, flattened once and cached per company.
 *
 * The key carries the company id because the cache store is shared by every tenant (and by
 * every Octane worker). A cache key without it is the cross-company leak the package's own
 * single-key cache would introduce.
 *
 * Nested objects stay nested (`numbering.invoice.prefix`) but a list is a value, not a path:
 * once a list is indexed it can never be read back with one `get()`, and `default_buckets`
 * is a list.
 */
final class CachedSettings
{
    private const TTL_SECONDS = 3600;

    /**
     * What this request/job already read, so a screen that asks for the warning window once per
     * row pays for it once. The binding is scoped, so this cannot outlive the request that made
     * it; `flush()` drops the entry when a write lands.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(private readonly Cache $cache) {}

    /** @return array<string, mixed> */
    public function all(int $companyId): array
    {
        return $this->memo[$companyId] ??= $this->cache->remember(
            $this->key($companyId),
            self::TTL_SECONDS,
            fn (): array => self::flatten((Company::query()->findOrFail($companyId)->settings) ?? []),
        );
    }

    /**
     * Writes go through `CompanySettings`, which calls this after the row is saved. Flushing
     * (rather than touching the TTL) is deliberate: a writer must not extend the life of the
     * value it just replaced. The memo goes with it — a reader in the same request must not
     * answer from the copy the writer just replaced.
     */
    public function flush(int $companyId): void
    {
        unset($this->memo[$companyId]);

        $this->cache->forget($this->key($companyId));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    public static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function key(int $companyId): string
    {
        return 'company-settings.'.$companyId;
    }
}
