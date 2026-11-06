<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ALERT — the single source of truth for "needs attention".
     *
     * Three things in this table are not in the sketch, and each one is here because the sketch
     * contradicted the design's own rule for the same aggregate:
     *
     * 1. `muted_until` — (line "`muted` with an expiry so muting cannot silently become
     *    permanent") requires an expiry, and the schema block in the design lists no column to hold it.
     *    A mute with no expiry is a resolved alert wearing a different name.
     * 2. `acknowledged_at` / `muted_*` / `resolved_*` as three separate dated decisions rather than
     *    one `resolved_at`. The state machine has three exits and they are made by different people
     *    for different reasons; one column would make "who dismissed this" unanswerable.
     * 3. `first_seen_at` / `last_seen_at` / `notify_count`. The cooldown promise in the design is "a
     *    nightly job cannot email the same expiring licence fourteen times" — which is a statement
     *    about how often a condition was *seen* and how many times it was *delivered*. Neither is
     *    derivable from a row that only records the first insert.
     *
     * `cooldown_key` is unique **per company**, not globally. The design writes `UK` beside it, but a
     * subject id is only unique inside its own tenant for the rows that have no ULID at all — two
     * companies' `ledger:verify` failure both name the subject `command/ledger:verify`, and a global
     * unique would let the first company's alert swallow the second's. Silently.
     *
     * `type` is a string + CHECK rather than a MySQL ENUM, for the reason repeated on every
     * value list in this app: the telematics phase adds `geofence` and `route_deviation` (already
     * listed here) and something later adds more. A CHECK edit is a deliberate act; an ENUM rebuild
     * is a table lock.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('type', 32);
            $table->string('severity', 8)->default('warning');

            // The thing the alert is about. Unconstrained for the design's own reason: the subject is a
            // truck's licence in Phase 1 and an invoice in Phase 4, and neither table exists yet.
            $table->string('subject_type', 120)->nullable();
            $table->char('subject_id', 26)->nullable();

            // The company business day the condition was true on (derived against the company's
            // zone, never the storage zone — see Validity::for()).
            $table->date('occurred_on');

            // type + subject + bucket, built by RaiseAlert. The bucket is what makes the cooldown
            // finite rather than permanent: a licence expiring on 2026-12-01 can warn at 60 days out
            // and again at 30, and must be able to warn about the *next* expiry after a renewal.
            $table->string('cooldown_key', 255);

            $table->json('payload')->nullable();
            $table->string('state', 12)->default('open');

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedTinyInteger('notify_count')->default(0);

            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('muted_until')->nullable();
            $table->foreignId('muted_by')->nullable()->constrained('users');
            $table->string('mute_reason', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->string('resolved_by_kind', 12)->nullable();
            $table->string('resolve_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'cooldown_key'], 'alerts_company_cooldown_unique');
            $table->index(['company_id', 'state', 'occurred_on'], 'alerts_company_state_occurred_index');
            $table->index(['company_id', 'type', 'severity'], 'alerts_company_type_severity_index');
            $table->index(['state', 'muted_until'], 'alerts_mute_expiry_index');
        });

        // The value list, plus `self_check_failure`. The last line — "every one is scheduled;
        // every failure raises an alert" — needs a type, and none of the ten fits: a digest
        // mismatch on the private disk is not a `posting_failure`, which reserves for a posting
        // that hit a locked period. Reusing that name would put integrity drift on the ledger
        // exceptions screen and hide it from the Owner where the accounting team would never look.
        DB::statement("alter table `alerts` add constraint `alerts_type_allowed` check (type in ('doc_expiry', 'service_due', 'overdue_invoice', 'fuel_variance', 'geofence', 'route_deviation', 'loan_due', 'cash_shortage', 'permit_expiry', 'posting_failure', 'self_check_failure'))");
        DB::statement("alter table `alerts` add constraint `alerts_severity_allowed` check (severity in ('info', 'warning', 'critical'))");
        DB::statement("alter table `alerts` add constraint `alerts_state_allowed` check (state in ('open', 'acknowledged', 'resolved', 'muted'))");

        // A subject is a pair, not half a pair — the documents_subject_is_a_pair rule repeated, for
        // the same reason: a policy or a screen that asks only one of them would call a subjectless
        // row "attached to something".
        DB::statement('alter table `alerts` add constraint `alerts_subject_is_a_pair` check ((subject_type is null and subject_id is null) or (subject_type is not null and subject_id is not null))');

        // Every exit from `open` is a dated decision by a named person. Three-valued logic is the
        // trap recorded twice: an expression that evaluates to UNKNOWN passes, so each arm
        // spells out `is not null` before comparing it.
        DB::statement('alter table `alerts` add constraint `alerts_acknowledge_dated` check ((acknowledged_at is null and acknowledged_by is null) or (acknowledged_at is not null and acknowledged_by is not null))');

        // A resolution can be made by a person *or* by the scheduler that raised it: The self-check
        // alerts exist to say "the nightly sweep found this", and the same sweep finding it clean must
        // close them, or the register fills with accurate alerts about problems that no longer exist.
        // `operational_events` solved the identical problem in slice 7 the same way — `actor_id`
        // nullable, `actor_kind` says which principal it was — so the rule is copied rather than
        // reinvented. The asymmetry that matters is in the last arm: a `user` resolution must name the
        // user, because "somebody resolved this" with nobody attached is the row the design refuses.
        DB::statement("alter table `alerts` add constraint `alerts_resolve_dated` check ((resolved_at is null and resolved_by_kind is null) or (resolved_at is not null and resolved_by_kind is not null and (resolved_by_kind = 'scheduler' or `resolved_by` is not null)))");
        DB::statement("alter table `alerts` add constraint `alerts_resolve_by_kind_allowed` check (resolved_by_kind is null or resolved_by_kind in ('user', 'scheduler'))");
        DB::statement('alter table `alerts` add constraint `alerts_mute_dated` check ((muted_until is null and muted_by is null and mute_reason is null) or (muted_until is not null and muted_by is not null))');

        // A mute is temporary, so it has to carry an end and a reason. And the state has to
        // agree with the columns: `resolved` without `resolved_at` is a row that claims a decision
        // nobody made, and `muted` past its expiry is the permanent mute the design forbids — that one is
        // enforced by the sweep that reopens it, but the pair rule stops a hand-edited row from
        // pretending.
        DB::statement('alter table `alerts` add constraint `alerts_state_matches_its_columns` check ((state = \'acknowledged\' and `acknowledged_at` is not null) or (state = \'resolved\' and `resolved_at` is not null) or (state = \'muted\' and `muted_until` is not null) or (state = \'open\'))');

        // A nullable reason is how you lose the plot six months later. Resolving an expiring
        // licence says "this is dealt with" about a paper that may not be, so the register has to say
        // how — the rule the design applies to a void, applied to the state that closes an alert.
        $charLength = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        DB::statement("alter table `alerts` add constraint `alerts_resolved_explained` check ((state <> 'resolved') or (`resolve_reason` is not null and {$charLength}(trim(`resolve_reason`)) > 0))");
        DB::statement("alter table `alerts` add constraint `alerts_muted_explained` check ((state <> 'muted') or (`muted_until` is not null and `mute_reason` is not null and {$charLength}(trim(`mute_reason`)) > 0))");

        // A resolved alert is finished; it cannot also be acknowledged by someone else afterwards,
        // which is what a race between two clerks on the register would otherwise leave behind.
        DB::statement('alter table `alerts` add constraint `alerts_resolved_is_final` check ((state <> \'resolved\') or (`acknowledged_at` is null or `resolved_at` >= `acknowledged_at`))');

        // first_seen_at <= last_seen_at: the sweep writes both, and a row whose condition was seen
        // again before it was first seen is a row something wrote with the wrong clock.
        DB::statement('alter table `alerts` add constraint `alerts_seen_in_order` check (last_seen_at >= first_seen_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
