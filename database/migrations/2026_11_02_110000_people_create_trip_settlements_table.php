<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trip settlement (the `TRIP ||--o{ TRIP_SETTLEMENT: reconciled_by`, the
     * `TripSettled`, the `completed → closed` witness): the sheet that says what the
     * driver's advance actually funded, what cash came back, and what allowance the trip
     * earned — the "office reconciles it later" promise made at the pump, collected.
     *
     * The four figures are stored, not derived, because they are a *judgement* signed by a
     * person: the advance total is what the trip cost rows issued, but `expenses_covered`
     * (how much of the driver's diesel came from the advance rather than office petty cash)
     * and `allowance` are decided here, with the screen pre-filling from the rows and the
     * driver's daily rate. The CHECKs keep the arithmetic honest: cleared money can never
     * exceed the advance, and the outstanding receivable is exactly what the three inputs
     * leave — the amount the cutover will one day call an unreconciled advance.
     *
     * `trip_id` is a names-only char(26) in the work-order style: the settlement belongs
     * to the trip's story, but People does not reach across the module map backwards
     * (draws OPS → PEOPLE, never the reverse); the live-row unique index (the trick)
     * gives one unsigned-or-settled settlement per trip.
     */
    public function up(): void
    {
        Schema::create('trip_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('reference', 40);
            $table->string('status', 12)->default('open');

            $table->char('trip_id', 26);
            $table->foreignId('driver_id')->constrained('drivers');

            $table->unsignedBigInteger('advance_total');
            $table->unsignedBigInteger('expenses_covered');
            $table->unsignedBigInteger('cash_returned');
            $table->unsignedBigInteger('outstanding_minor');
            $table->unsignedBigInteger('allowance');

            $table->char('entry_id', 26)->nullable();
            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'trip_settlements_company_reference_unique');
            $table->index(['company_id', 'trip_id'], 'trip_settlements_company_trip_index');
            $table->index(['company_id', 'driver_id', 'status'], 'trip_settlements_company_driver_status_index');
        });

        DB::statement('alter table `trip_settlements` add column `live_trip_id` char(26) generated always as (case when `status` <> \'void\' then `trip_id` else null end) stored');
        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_live_trip_unique` unique (`company_id`, `live_trip_id`)');

        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_status_allowed` check (status in (\'open\', \'settled\', \'void\'))');
        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_cleared_within_advance` check (expenses_covered + cash_returned <= advance_total)');
        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_outstanding_is_the_remainder` check (outstanding_minor = advance_total - expenses_covered - cash_returned)');
        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_needs_a_number` check (advance_total + expenses_covered + cash_returned + allowance > 0)');
        DB::statement('alter table `trip_settlements` add constraint `trip_settlements_settled_needs_signer` check (status != \'settled\' or (settled_by is not null and settled_at is not null and entry_id is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_settlements');
    }
};
