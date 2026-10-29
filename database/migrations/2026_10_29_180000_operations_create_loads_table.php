<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 LOAD — the loading schedule behind §15.2 Phase 2.
     *
     * A trip carries loads rather than one load, because the corridor that pays is the one with a
     * return load on the same truck: §4.1's rule is that multi-leg and return work is more trips
     * against the same job, and each of those legs picks up its own tonnes. The tonnage total is what
     * §10.2's `assigned → loaded` gate measures against the truck **and** the trailer.
     *
     * `waybill_document_id` is the piece of evidence, and it is a ULID FK to `documents.id` — the
     * document module owns the bytes and the retention class, so a load never stores a file path. The
     * gate asks only that the paper exists in this company and has not been voided; naming a random
     * document as a waybill is a data-entry problem the register's subject link makes visible rather
     * than something a `CHECK` can decide.
     *
     * The trip reference is **composite** (`trip_id`, `company_id`) for the same reason §8 makes
     * `trips.job_id` composite: a bare ULID FK accepts a cross-tenant pair, and a load that belongs to
     * another company's trip is exactly the row a dispatch board would show twice.
     */
    public function up(): void
    {
        // Added here rather than in the trips migration because a table's own composite key is only
        // worth having once a second table needs to point at it: `id` is already unique, and the pair
        // exists so a foreign key can assert "same company" as well as "same row".
        DB::statement('alter table `trips` add constraint `trips_id_company_unique` unique (`id`, `company_id`)');

        Schema::create('loads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('trip_id', 26);

            $table->string('commodity', 120);
            $table->decimal('tonnes_10_2', 8, 2);
            $table->decimal('cubic_meters_10_2', 8, 2)->nullable();

            $table->string('consignee', 120)->nullable();
            $table->string('origin', 80)->nullable();
            $table->string('destination', 80)->nullable();
            $table->string('stop_sequence', 8)->nullable();

            $table->char('waybill_document_id', 26)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'trip_id'], 'loads_company_trip_index');
            $table->index(['company_id', 'consignee'], 'loads_company_consignee_index');
        });

        DB::statement('alter table `loads` add constraint `loads_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`) on delete cascade');
        DB::statement('alter table `loads` add constraint `loads_waybill_document_fk` foreign key (`waybill_document_id`) references `documents` (`id`) on delete set null');

        DB::statement('alter table `loads` add constraint `loads_tonnes_positive` check (tonnes_10_2 > 0)');
        DB::statement('alter table `loads` add constraint `loads_cubic_not_negative` check (cubic_meters_10_2 is null or cubic_meters_10_2 >= 0)');
        DB::statement('alter table `loads` add constraint `loads_commodity_not_blank` check (char_length(trim(commodity)) > 0)');
        DB::statement('alter table `loads` add constraint `loads_stop_sequence_short` check (stop_sequence is null or char_length(stop_sequence) between 1 and 8)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loads');

        DB::statement('alter table `trips` drop index `trips_id_company_unique`');
    }
};
