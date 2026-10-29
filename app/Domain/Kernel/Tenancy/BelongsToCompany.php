<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The write half of T2: stamp the company on create, refuse a mismatch, and filter every
 * read through CompanyScope. Put this on every business model; the architecture lint and
 * the fail-closed scope together make forgetting it loud.
 *
 * @mixin Model
 *
 * @property int $company_id
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            $context = CurrentCompany::id();

            // getAttribute()/setAttribute() rather than ->company_id: the column belongs to the
            // class using this trait, so the trait cannot legitimately declare it.
            $declared = $model->getAttribute('company_id');

            if ($declared === null) {
                $model->setAttribute('company_id', $context);

                return;
            }

            // An Action building a row for another company is a bug, not a feature.
            if ((int) $declared !== $context) {
                throw MissingCompanyContext::companyMismatch($model, $context, (int) $declared);
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The only sanctioned way to read across companies: totals, the seeder, and `*:verify`
     * commands. Named rather than `withoutGlobalScope()` so it shows up in a grep and in
     * review. Never call it from an HTTP-facing read.
     *
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    public function scopeWithoutCompanyScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(CompanyScope::class);
    }
}
