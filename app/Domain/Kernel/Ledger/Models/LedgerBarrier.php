<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The §6.3 emergency hatch as a model, so lifting the ledger guard is something the application
 * can do *with a record* rather than only by hand in a SQL client.
 *
 * Not tenant-scoped: it is a single-row table below tenancy on purpose — the barrier is the fact
 * that immutability is off for this database right now, which is one fact, not one per company.
 *
 * @property int $id
 * @property string $reason
 * @property int|null $lifted_by
 * @property Carbon|null $lifted_at
 */
final class LedgerBarrier extends Model
{
    protected $table = 'gl_ledger_barrier';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id', 'reason', 'lifted_by', 'lifted_at'];

    protected $casts = ['lifted_at' => 'datetime'];

    public static function isLifted(): bool
    {
        return self::query()->where('id', 1)->exists();
    }

    /**
     * Raise the barrier, run the fix, lower it — always, including when the fix throws, because a
     * barrier left up is an unaudited window in which the ledger can be rewritten.
     *
     * Callers are maintenance migrations and the `ledger:*` commands. Nothing on a request path
     * may call this: it is the one switch that turns §6.3 off.
     */
    public static function withLiftedGuard(string $reason, ?int $by, callable $fix): mixed
    {
        self::query()->insert([
            'id' => 1,
            'reason' => $reason,
            'lifted_by' => $by,
            'lifted_at' => Carbon::now(),
        ]);

        try {
            return $fix();
        } finally {
            self::query()->where('id', 1)->delete();
        }
    }
}
