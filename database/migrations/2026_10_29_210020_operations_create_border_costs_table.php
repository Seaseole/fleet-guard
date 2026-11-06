<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BORDER_COST — one charge paid at one gate, and the link to the trip cost it posted.
     *
     * The row is separate from `trip_costs` because a gate charges several times: duty, the clearing
     * agent, a road-user fee and scanning are four costs on one crossing, and `trip_costs`'s source
     * pair is unique per crossing. Each border cost posts its own trip cost (type `border`), and
     * `trip_cost_id` is the one-to-one edge between the operational record and the accounting one.
     *
     * The FX block is copied from the posted trip cost rather than recomputed here, so the row and
     * the ledger cannot disagree about what the bank said.
     *
     * Deferred to Phase 6,: `recoverable_input_tax`, `tax_rate_id`,
     * `receipt_document_id` and `payable_to_agent_id`. Capturing the kind and the money is this
     * slice's job; the tax split and the agent's float belong to the slice that reconciles them.
     *
     * The primary key is a ULID although the sketch writes `bigint`: the row is the `source_id`
     * of its own trip cost, that column is `char(26)`, and an auto-increment id would not fit. The
     * other child tables that post through `(source_type, source_id)` make the same choice.
     */
    public function up(): void
    {
        Schema::create('border_costs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('border_crossing_id', 26);

            $table->string('kind', 20);
            $table->string('description', 160)->nullable();

            //, Applied verbatim and copied from the trip cost this row posted.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3);
            $table->unsignedBigInteger('base_amount');

            $table->date('occurred_on');
            $table->char('trip_cost_id', 26)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'border_crossing_id'], 'border_costs_crossing_index');
            $table->unique('trip_cost_id', 'border_costs_trip_cost_unique');
        });

        DB::statement('alter table `border_costs` add constraint `border_costs_crossing_company_fk` foreign key (`border_crossing_id`, `company_id`) references `border_crossings` (`id`, `company_id`)');
        DB::statement('alter table `border_costs` add constraint `border_costs_trip_cost_fk` foreign key (`trip_cost_id`) references `trip_costs` (`id`)');

        DB::statement('alter table `border_costs` add constraint `border_costs_kind_allowed` check (kind in (\'duty\', \'clearing_agent\', \'permit\', \'road_user_fee\', \'transit_bond\', \'scanning\', \'other\'))');
        DB::statement('alter table `border_costs` add constraint `border_costs_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `border_costs` add constraint `border_costs_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `border_costs` add constraint `border_costs_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('border_costs');
    }
};
