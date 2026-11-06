<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DOCUMENT — one document, many versions, two independent axes.
     *
     * `lifecycle` is stored because superseding and archiving are decisions; `validity` is
     * deliberately **not** a column, because the whole point is that a driver licence and a
     * weighbridge ticket must not each carry their own hand-coded idea of "expired" — it derives
     * from `expires_on` in one place.
     *
     * `type` is a string + enum + CHECK rather than a MySQL ENUM for the reason repeated: a
     * new document kind (roadworthiness, transit bond) must be a value list edit, not a table
     * rebuild.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('type', 32);
            $table->string('title', 200);

            // The subject the document evidences — a trip's POD, a truck's licence, a customer's
            // contract. Deliberately unconstrained: every candidate table arrives in a later
            // phase, and a polymorphic pair is exactly what rejected for the ledger's
            // *reporting* dimensions. A document's subject is never grouped by in a report; it is
            // only followed back to the row, so no index is lost by keeping it polymorphic.
            $table->string('morph_type', 120)->nullable();
            $table->char('morph_id', 26)->nullable();

            $table->date('expires_on')->nullable();
            $table->string('lifecycle', 12)->default('draft');
            $table->string('retention_class', 12)->default('operational');

            // "Role-based visibility via documents.visibility + policy": the role keys that
            // may see this document. An empty list means "anyone holding documents.view", which is
            // what makes the column additive rather than a second permission system.
            $table->json('visibility')->nullable();
            $table->boolean('confidential')->default(false);
            $table->string('notes', 500)->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type', 'lifecycle'], 'documents_company_type_lifecycle_index');
            $table->index(['company_id', 'expires_on'], 'documents_company_expires_index');
            $table->index(['morph_type', 'morph_id'], 'documents_subject_index');
        });

        // Blueprint has no CHECK support, so the value lists are raw DDL on both engines.
        DB::statement('alter table `documents` add constraint `documents_type_allowed` check (type in (\'contract\', \'licence\', \'insurance\', \'permit\', \'delivery_note\', \'pod\', \'customs\', \'work_order\', \'maintenance\', \'weighbridge\'))');
        DB::statement('alter table `documents` add constraint `documents_lifecycle_allowed` check (lifecycle in (\'draft\', \'current\', \'superseded\', \'archived\', \'voided\'))');
        DB::statement('alter table `documents` add constraint `documents_retention_allowed` check (retention_class in (\'tax\', \'legal\', \'operational\', \'none\'))');

        // A subject is a pair, not half a pair. A `morph_id` with no `morph_type` is a row that
        // points at nothing and would be reported as "attached" by any policy that only asks one.
        DB::statement('alter table `documents` add constraint `documents_subject_is_a_pair` check ((morph_type is null and morph_id is null) or (morph_type is not null and morph_id is not null))');

        // Taking a document out of use is a dated event, not just a word in a column: an archived
        // row with no `archived_at` cannot be used by the retention report to tell which
        // papers are held past their class, and a voided row without a timestamp is unauditable.
        DB::statement('alter table `documents` add constraint `documents_retirement_dated` check ((lifecycle <> \'archived\' or archived_at is not null) and (lifecycle <> \'voided\' or voided_at is not null))');

        // The design makes the reason for a void mandatory, and the row that carries the void is this one —
        // the operational event is the trail, but the register itself has to state it too, or the
        // document list shows "Voided" with nothing next to it and the reason lives only in a table
        // nobody opens from the register.
        //
        // Same three-valued-logic trap as `operational_events_destructive_explained`: `char_length` of
        // NULL is NULL, and a CHECK whose expression is UNKNOWN does not fire.
        $charLength = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        DB::statement("alter table `documents` add constraint `documents_void_explained` check (lifecycle <> 'voided' or (`void_reason` is not null and {$charLength}(trim(`void_reason`)) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
