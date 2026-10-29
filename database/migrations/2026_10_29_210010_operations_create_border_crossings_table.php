<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 / §4.4 BORDER_CROSSING — one gate event on one trip, with §10.9's machine.
     *
     * The machine reaches `costs_captured` here; `agent_reconciled` and `closed` are Phase 6, because
     * they require an agent float to reconcile against and this build has no `border_costs` table
     * to reconcile with. The CHECK lists all six values anyway: the enum is the vocabulary, and a
     * later migration that opens the last two edges does not also have to rewrite a constraint.
     *
     * `weighbridge_ticket_id` is stored without a foreign key because WEIGHBRIDGE_TICKET arrives in
     * Phase 6. §6.2's dimension hazard applies: a column that cannot reference a table nobody has
     * built is a deferral, and the Phase 6 migration adds the key.
     *
     * `(trip_id, border_post_id, direction)` is unique: a retried plan returns the row it already
     * made rather than counting the same gate twice on one leg of the trip.
     */
    public function up(): void
    {
        Schema::create('border_crossings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('trip_id', 26);
            $table->foreignId('border_post_id')->constrained('border_posts');
            $table->char('country_code', 2);

            $table->string('direction', 5);
            $table->string('status', 20)->default('planned');

            // §10.1's three state columns, as every first-party aggregate carries them: the trait
            // writes the event before changing these, and `previous_state` is what lets the trail be
            // reconstructed without parsing `operational_events` in reverse.
            $table->string('previous_state', 32)->nullable();
            $table->datetime('state_changed_at')->nullable();

            $table->datetime('entered_at')->nullable();
            $table->datetime('exited_at')->nullable();

            $table->string('transit_doc_no', 60)->nullable();
            $table->char('weighbridge_ticket_id', 26)->nullable();
            $table->string('clearance_state', 40)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'trip_id', 'border_post_id', 'direction'], 'border_crossings_trip_post_direction_unique');
            // The composite key `border_costs` points at, the same shape `trips` carries for its
            // children: a child may only name a parent in its own company.
            $table->unique(['id', 'company_id'], 'border_crossings_id_company_unique');
            $table->index(['company_id', 'status'], 'border_crossings_company_status_index');
        });

        DB::statement('alter table `border_crossings` add constraint `border_crossings_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');

        DB::statement('alter table `border_crossings` add constraint `border_crossings_direction_allowed` check (direction in (\'entry\', \'exit\'))');
        DB::statement('alter table `border_crossings` add constraint `border_crossings_status_allowed` check (status in (\'planned\', \'entered\', \'exited\', \'costs_captured\', \'agent_reconciled\', \'closed\'))');
        DB::statement('alter table `border_crossings` add constraint `border_crossings_country_upper` check (country_code = upper(country_code))');

        // §10.9's machine in data form: an exit cannot precede an entry, and a state past `entered`
        // cannot be reached on a row that never recorded when the truck went through.
        DB::statement('alter table `border_crossings` add constraint `border_crossings_exit_follows_entry` check (`exited_at` is null or (`entered_at` is not null and `exited_at` >= `entered_at`))');
        DB::statement('alter table `border_crossings` add constraint `border_crossings_state_has_times` check (status in (\'planned\', \'entered\') or (`entered_at` is not null and `exited_at` is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('border_crossings');
    }
};
