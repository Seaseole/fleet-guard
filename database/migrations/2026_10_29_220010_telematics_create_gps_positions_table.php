<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GPS_POSITION — the raw fix, append-only and high-volume.
     *
     * The key is a `bigint` auto-increment, not a ULID: at 21 vehicles × a 30 s ping this is
     * ~60 k rows a day, nothing outside the ingest path ever fetches one by id, and a 26-character
     * key on every row of the biggest table in the schema buys nothing. The row's identity for the
     * outside world is `(provider, provider_message_id)` — the webhook retry key.
     *
     * `provider_message_id` is unique **per company** as well as per provider (the tenant is the
     * leading column of every unique key). A provider's message ids are opaque strings, and two
     * tenants would otherwise share the namespace of whichever provider id happened to collide.
     *
     * `raw_json` keeps the payload the provider sent. The normalised columns are what the app reads;
     * the payload is what settles an argument with the provider about a fix that looked wrong.
     *
     * Retention is not enforced here. The four-week window plus monthly archive is Phase 7,
     * because a prune with no archive is data loss. Until then the table grows and is indexed for
     * the two queries that exist: a truck's recent track and a trip's track.
     */
    public function up(): void
    {
        Schema::create('gps_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('provider', 32);
            $table->string('provider_message_id', 64);

            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26)->nullable();

            $table->datetime('fixed_at');
            $table->decimal('lat_10_7', 10, 7);
            $table->decimal('lng_10_7', 10, 7);
            $table->unsignedSmallInteger('speed_kph')->nullable();
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();

            $table->json('raw_json')->nullable();
            $table->datetime('ingested_at');

            // The dedupe key: a webhook retried after a timeout returns the fix it already had
            // instead of plotting the truck twice.
            $table->unique(['company_id', 'provider', 'provider_message_id'], 'gps_positions_provider_message_unique');
            $table->index(['company_id', 'truck_id', 'fixed_at'], 'gps_positions_truck_fixed_index');
            $table->index(['company_id', 'trip_id', 'fixed_at'], 'gps_positions_trip_fixed_index');
            // The Phase 7 retention sweep reads this one, and so does "what arrived in the last hour".
            $table->index(['company_id', 'fixed_at'], 'gps_positions_company_fixed_index');
        });

        // A position may name a trip, and that trip has to be in the same company (the composite
        // key). A fix is never deleted by the app, so the FK is restrictive rather than cascading:
        // history that vanishes with its trip is not history.
        DB::statement('alter table `gps_positions` add constraint `gps_positions_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');

        // Boundary validity written into the schema: a coordinate outside the planet, a negative
        // speed or a heading of 400° is a parser bug, and storing it would put a truck in the ocean
        // on the map rather than fail the message that said so.
        DB::statement('alter table `gps_positions` add constraint `gps_positions_lat_range` check (`lat_10_7` between -90 and 90)');
        DB::statement('alter table `gps_positions` add constraint `gps_positions_lng_range` check (`lng_10_7` between -180 and 180)');
        DB::statement('alter table `gps_positions` add constraint `gps_positions_heading_range` check (`heading_deg` is null or `heading_deg` between 0 and 359)');
        DB::statement('alter table `gps_positions` add constraint `gps_positions_message_named` check (char_length(trim(`provider_message_id`)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_positions');
    }
};
