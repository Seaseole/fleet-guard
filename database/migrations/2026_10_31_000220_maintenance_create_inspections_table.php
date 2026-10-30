<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 INSPECTION — a safety check that happened, with the checklist, the defects and the
     * photographs that make it evidence (§15.2 phase 3, slice 3f).
     *
     * §10.6's `completed → inspected` is the second pair of eyes on one work order; this table is
     * the standing inspection register — pre-trip, periodic and tachograph checks — and the two share
     * the word but not the row. An inspection is not a work order and must not become one: most pass,
     * and a register that only remembers the failures cannot show that the fleet is being checked.
     * `work_order_id` is the ER's `WORK_ORDER ||--o{ INSPECTION` edge, optional because a periodic
     * check may be done at the roadside or a yard with no job open; when present it must name the
     * same truck, which the Action checks.
     *
     * `checklist` stores the fixed item list's answers (`{"brakes": true, ...}`), `defects` stores the
     * lines a person wrote, and `photo_document_ids` stores references to the documents module rather
     * than the files. The Action enforces the one rule JSON cannot: a pass carries no defect, and a
     * fail or advisory names at least one.
     */
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('kind', 10);
            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26)->nullable();
            $table->char('work_order_id', 26)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers');

            $table->dateTime('done_at');
            $table->string('result', 9);

            $table->json('checklist')->nullable();
            $table->json('defects')->nullable();
            $table->json('photo_document_ids')->nullable();

            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('trips');
            $table->foreign('work_order_id')->references('id')->on('work_orders');
            $table->index(['company_id', 'truck_id', 'done_at'], 'inspections_company_truck_done_index');
            $table->index(['company_id', 'result', 'done_at'], 'inspections_company_result_index');
            $table->index(['company_id', 'trip_id'], 'inspections_company_trip_index');
            $table->index(['company_id', 'work_order_id'], 'inspections_company_work_order_index');
        });

        DB::statement("alter table `inspections` add constraint `inspections_kind_allowed` check (kind in ('pre_trip', 'periodic', 'tacho'))");
        DB::statement("alter table `inspections` add constraint `inspections_result_allowed` check (result in ('pass', 'fail', 'advisory'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
