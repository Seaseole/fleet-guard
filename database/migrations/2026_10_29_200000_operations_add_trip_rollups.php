<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §6.6's write-time projection on TRIP — the cost buckets `trip_costs` and the ledger feed.
     *
     * These columns are not a cache: `TripCostRollup` writes them **inside** the same transaction that
     * posts the journal entry, and `trips:recost` recomputes them from `gl_journal_lines` to prove the
     * stored figure still describes the lines. The arithmetic is enforced by CHECKs rather than trusted
     * to the one class that writes it, so a bad rollup is a refused write, not a wrong dashboard.
     *
     * `contribution_base` is signed — a trip can lose money — while the cost buckets are unsigned
     * because they are sums of debits; a credit that makes a bucket negative is a contra the rollup
     * treats as an error rather than a discount.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('revenue_base')->default(0);
            $table->unsignedBigInteger('fuel_cost_base')->default(0);
            $table->unsignedBigInteger('maintenance_cost_base')->default(0);
            $table->unsignedBigInteger('border_cost_base')->default(0);
            $table->unsignedBigInteger('driver_cost_base')->default(0);
            $table->unsignedBigInteger('other_direct_cost_base')->default(0);
            $table->unsignedBigInteger('direct_cost_base')->default(0);
            $table->bigInteger('contribution_base')->default(0);
            $table->timestamp('ledger_posted_at')->nullable();
        });

        // The projection's own arithmetic. MySQL can compare the long form directly, and doing it here
        // means the dashboard's number cannot disagree with the five buckets it is made from.
        DB::statement('alter table `trips` add constraint `trips_rollup_direct_sums` check (direct_cost_base = fuel_cost_base + maintenance_cost_base + border_cost_base + driver_cost_base + other_direct_cost_base)');
        DB::statement('alter table `trips` add constraint `trips_rollup_contribution` check (contribution_base = cast(revenue_base as signed) - cast(direct_cost_base as signed))');
    }

    public function down(): void
    {
        // MySQL refuses to drop a column a CHECK still cites, so the two constraints go first.
        DB::statement('alter table `trips` drop check `trips_rollup_contribution`');
        DB::statement('alter table `trips` drop check `trips_rollup_direct_sums`');

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn([
                'revenue_base', 'fuel_cost_base', 'maintenance_cost_base', 'border_cost_base',
                'driver_cost_base', 'other_direct_cost_base', 'direct_cost_base', 'contribution_base',
                'ledger_posted_at',
            ]);
        });
    }
};
