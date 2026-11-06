<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * T3 in DESIGN.md, and the whole point of the tenancy work: a scoped model read with
 * no company context throws rather than quietly returning every row. A silent cross-tenant
 * read is the one failure mode this model cannot afford.
 */
final class MissingCompanyContext extends RuntimeException
{
    public static function forRead(): self
    {
        return new self(
            'No company context, so a scoped read would return rows from every company. Establish one first: '
            .'HTTP requests run through the company middleware, CLI commands must pass --company=<id>, and '
            .'queued jobs have no authenticated user, so they must call CurrentCompany::force() from their payload.'
        );
    }

    public static function forWrite(Model $model): self
    {
        return new self(sprintf(
            'Cannot persist a %s without a company context.',
            class_basename($model),
        ));
    }

    public static function companyMismatch(Model $model, int $context, int $attempted): self
    {
        return new self(sprintf(
            'Refusing to write a %s belonging to company %d while the current context is company %d. '
            .'Cross-company writes need an explicit migration command, not an ordinary save.',
            class_basename($model),
            $attempted,
            $context,
        ));
    }
}
