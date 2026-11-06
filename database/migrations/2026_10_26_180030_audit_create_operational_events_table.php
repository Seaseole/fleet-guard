<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OPERATIONAL_EVENT — the state-machine trail: which aggregate moved, from what to what,
     * who moved it, and why (the "every state change is an event with an actor").
     *
     * Separate from `security_events` because the question it answers is different. That table
     * answers "who touched this paper"; this one answers "why is this work order closed" and is
     * what the trip audit and Phase 5's period-close investigation read.
     *
     * A document's `current → superseded` and `→ voided` transitions land here, which is how the
     * Lifecycle can be reconstructed after the fact rather than merely asserted.
     */
    public function up(): void
    {
        Schema::create('operational_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('aggregate_type', 120);
            $table->char('aggregate_id', 26);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);

            // A transition can be caused by a person, a kiosk token, the scheduler or the system
            // itself (the two non-user principals), so `actor_id` is nullable and `actor_kind`
            // carries the distinction — attribution survives the shared driver phone.
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_kind', 12)->default('user');

            // Mandatory for destructive transitions, and the CHECK below is what makes it
            // mandatory rather than a form courtesy. It is a separate column and not a key inside
            // `context` because a reason has to be found, indexed and read without knowing which
            // aggregate moved — `grep` for a reason is a `select reason`, not a json path.
            $table->string('reason', 255)->nullable();

            $table->json('context')->nullable();
            $table->datetime('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'occurred_at'], 'operational_events_company_time_index');
            $table->index(['aggregate_type', 'aggregate_id'], 'operational_events_aggregate_index');
        });

        DB::statement('alter table `operational_events` add constraint `operational_events_actor_kind_allowed` check (actor_kind in (\'user\', \'kiosk\', \'system\', \'scheduler\'))');

        // A destructive transition without a stated reason is the one this table exists to
        // prevent, so it is a data rule and not a form courtesy. Voiding a document, reopening a
        // trip and reversing a payment all arrive here with a reason; a non-destructive move does
        // not need one.
        //
        // `reason is not null` is not belt-and-braces alongside the length test — it is the term that
        // makes the constraint exist. A CHECK passes when its expression evaluates to UNKNOWN, and
        // `char_length(trim(NULL)) > 0` is NULL, so the obvious form of this rule accepts exactly the
        // row it was written to refuse. Measured on MySQL 9.1 (2026-10-26): without the `is not null`
        // term, `to_state = 'voided', reason = NULL` inserted cleanly; with it, 3819. Every nullable
        // column in a CHECK needs the same treatment, on either engine.
        $charLength = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        DB::statement("alter table `operational_events` add constraint `operational_events_destructive_explained` check (to_state not in ('voided', 'reversed', 'written_off') or (`reason` is not null and {$charLength}(trim(`reason`)) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_events');
    }
};
