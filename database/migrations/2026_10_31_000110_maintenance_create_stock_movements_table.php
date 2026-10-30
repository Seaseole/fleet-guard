<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 STOCK_MOVEMENT — every receipt, issue and physical count against a part, with the figures
     * the shelf held immediately afterwards (§15.2 phase 3).
     *
     * The after-snapshots are stored, not derived: a later receipt changes the moving average, and a
     * movement read at report time would answer with today's average for units that left last month.
     *
     * `quantity_delta_x100` and `value_delta_base` are signed — a count can find more or fewer units —
     * and the CHECK keeps their signs agreeing. A count that agrees with the book is still a row with
     * two zeroes and a date, because "the shelf was right" is the fact the next disagreement is read
     * against.
     *
     * `work_order_id` is what makes an issue a workshop fact rather than a stock write: a part that
     * left the store for a job names the job, and the CHECK refuses an issue that does not.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('part_id')->constrained('parts');

            $table->string('kind', 12);
            $table->bigInteger('quantity_delta_x100');
            $table->bigInteger('value_delta_base');

            $table->unsignedBigInteger('quantity_after_x100');
            $table->unsignedBigInteger('value_after_base');
            $table->unsignedBigInteger('average_cost_minor_after');

            $table->char('work_order_id', 26)->nullable();
            $table->unsignedBigInteger('unit_cost_minor')->nullable();

            $table->date('moved_on');
            $table->string('note', 160)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'part_id', 'moved_on'], 'stock_movements_part_date_index');
            $table->index(['company_id', 'work_order_id'], 'stock_movements_order_index');

            $table->foreign('work_order_id')->references('id')->on('work_orders');
        });

        DB::statement("alter table `stock_movements` add constraint `stock_movements_kind_allowed` check (kind in ('receipt', 'issue', 'count'))");
        DB::statement("alter table `stock_movements` add constraint `stock_movements_moved_something` check (quantity_delta_x100 <> 0 or kind = 'count')");
        DB::statement('alter table `stock_movements` add constraint `stock_movements_signs_agree` check ((quantity_delta_x100 >= 0 and value_delta_base >= 0) or (quantity_delta_x100 <= 0 and value_delta_base <= 0))');
        DB::statement('alter table `stock_movements` add constraint `stock_movements_empty_is_worth_nothing` check (quantity_after_x100 > 0 or (value_after_base = 0 and average_cost_minor_after = 0))');
        DB::statement("alter table `stock_movements` add constraint `stock_movements_issue_names_an_order` check (kind <> 'issue' or work_order_id is not null)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
