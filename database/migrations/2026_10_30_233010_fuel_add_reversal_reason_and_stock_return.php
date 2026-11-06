<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The correction path: an issue is never edited, so the reversal needs somewhere to say why,
     * and a bulk tank needs a movement kind that reads as stock coming back.
     *
     * `reversal_reason` is `nullable` because a posted issue is not reversed yet. It is required by
     * the Action, not by a CHECK: the reason is only mandatory on the transition, and a row that has
     * never been reversed has nothing to explain.
     *
     * The movement CHECK gains `reversal` so the yard tank's book distinguishes "diesel went out to
     * a trip" from "the trip's issue was undone and the diesel is back". A reversal still obeys the
     * signs-agree and moved-something constraints — it is a movement like any other.
     */
    public function up(): void
    {
        Schema::table('fuel_issues', function ($table): void {
            $table->string('reversal_reason', 160)->nullable()->after('exception_reason');
        });

        DB::statement('alter table `bulk_tank_movements` drop check `bulk_tank_movements_kind_allowed`');
        DB::statement('alter table `bulk_tank_movements` add constraint `bulk_tank_movements_kind_allowed` check (kind in (\'receipt\', \'issue\', \'dip\', \'reversal\'))');
    }

    public function down(): void
    {
        DB::statement('alter table `bulk_tank_movements` drop check `bulk_tank_movements_kind_allowed`');
        DB::statement('alter table `bulk_tank_movements` add constraint `bulk_tank_movements_kind_allowed` check (kind in (\'receipt\', \'issue\', \'dip\'))');

        Schema::table('fuel_issues', function ($table): void {
            $table->dropColumn('reversal_reason');
        });
    }
};
