<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BREAKDOWN — a truck stopped, where, why, and what it cost the operation
     * (phase 3, slice 3f).
     *
     * A breakdown is an operational fact before it is a repair: the hours lost and the cause are what
     * the owner reads, and the work order is what the workshop answers with. `work_order_id` is
     * therefore nullable — the breakdown is recorded when the dispatcher hears it, and the repair is
     * raised or linked afterwards, sometimes by somebody else.
     *
     * `recoverable_from_driver` is a claim about cause, so the table refuses it beside any cause but
     * `driver`: a mechanical failure or a road hazard is not recoverable from the person who was
     * driving, and a row that said so would be a claim nobody could defend. Whether the amount is in
     * fact recovered is Phase 4's settlement (the recovery posting), not this row.
     */
    public function up(): void
    {
        Schema::create('breakdowns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26)->nullable();

            $table->dateTime('occurred_at');
            $table->string('location', 120);
            $table->string('description', 500);
            $table->unsignedSmallInteger('hours_lost')->default(0);

            $table->char('work_order_id', 26)->nullable();
            $table->string('cause', 12);
            $table->boolean('recoverable_from_driver')->default(false);

            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('trips');
            $table->foreign('work_order_id')->references('id')->on('work_orders');

            $table->index(['company_id', 'truck_id', 'occurred_at'], 'breakdowns_company_truck_occurred_index');
            $table->index(['company_id', 'cause'], 'breakdowns_company_cause_index');
        });

        DB::statement("alter table `breakdowns` add constraint `breakdowns_cause_allowed` check (cause in ('mechanical', 'driver', 'road', 'load', 'other'))");
        DB::statement("alter table `breakdowns` add constraint `breakdowns_recovery_names_a_driver` check (recoverable_from_driver = 0 or cause = 'driver')");
    }

    public function down(): void
    {
        Schema::dropIfExists('breakdowns');
    }
};
