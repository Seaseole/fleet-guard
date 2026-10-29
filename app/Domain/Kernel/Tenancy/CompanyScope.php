<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The read half of T2/T3. Attached by the BelongsToCompany trait.
 *
 * `remove()` is inherited from GlobalScope, which is what makes `withoutCompanyScope()` a
 * visible, greppable escape hatch rather than a silent default.
 *
 * @implements Scope<Model>
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->getTable().'.company_id',
            CurrentCompany::id(),
        );
    }
}
