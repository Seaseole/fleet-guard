<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.4 DOCUMENT_VERSION — immutable bytes with a mutable "which one is current" pointer.
     *
     * A version is never edited or replaced in place: uploading a new scan creates another row, and
     * the supersede step (§10.8) moves the current flag. That is what lets §14.5's retention report
     * and an auditor answer "what did we hold on 14 March", which an overwrite cannot.
     *
     * `sha256` is the integrity claim, recorded at write time from the bytes actually stored — it
     * is what `documents:verify-integrity` re-checks against the disk, and the only defence that
     * means anything against a bucket object silently changing underneath the row.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('document_id', 26);

            $table->unsignedSmallInteger('version_seq');
            $table->string('disk', 32)->default('documents_private');
            $table->string('path', 320);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->boolean('is_current')->default(false);
            $table->string('virus_scan_status', 12)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_detail', 191)->nullable();

            $table->datetime('uploaded_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->string('replaced_reason', 255)->nullable();

            // §10.8: one current version per document, expressed the way both engines can do it.
            // MariaDB and MySQL have no partial index, so the flag is a STORED generated column
            // that equals the document id only while current — and a UNIQUE index across it makes
            // "two current versions" impossible below the application. NULLs are distinct in a
            // MySQL unique index, which is exactly the behaviour wanted here: every non-current
            // version escapes the constraint and only one can occupy it.
            // `nullable()` is load-bearing, not a formality: the expression yields NULL for every
            // non-current version, and a NOT NULL generated column would make the second version of
            // any document an insert error instead of a superseded history row.
            //
            // `storedAs`, not `generatedAs()->stored()`: MySqlGrammar's modifier list carries
            // VirtualAs/StoredAs and no Generated modifier at all, so the latter is silently
            // discarded and the column comes back as a plain writable `char(26) null` — a guard
            // that exists in the migration file but not in the database. Measured on MySQL 9.1
            // (2026-10-26): the emission order `char(26) as (…) stored null` is accepted, and the
            // duplicate-current insert is refused by the unique index.
            $table->char('current_slot', 26)
                ->nullable()
                ->storedAs('case when `is_current` then `document_id` else null end');

            $table->unique(['document_id', 'version_seq'], 'document_versions_document_seq_unique');
            $table->unique(['document_id', 'current_slot'], 'document_versions_one_current_unique');
            $table->index(['company_id', 'uploaded_at'], 'document_versions_company_uploaded_index');

            $table->foreign('document_id')->references('id')->on('documents');
        });

        DB::statement('alter table `document_versions` add constraint `document_versions_scan_status_allowed` check (virus_scan_status in (\'pending\', \'clean\', \'infected\'))');
        DB::statement('alter table `document_versions` add constraint `document_versions_seq_positive` check (version_seq > 0)');
        // `> 0` and not `>= 0`: the column is unsigned, so a negative could never reach a CHECK — and
        // a constraint that cannot fire is a comment pretending to be a rule. Zero is reachable, and
        // §14.4's answer to it is that a version with no bytes is not a version.
        DB::statement('alter table `document_versions` add constraint `document_versions_size_recorded` check (size_bytes > 0)');

        // The bytes are addressed, not described: a row whose path or digest is empty is a version
        // that can be listed but never served, which is worse than a rejected upload.
        $charLength = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        DB::statement("alter table `document_versions` add constraint `document_versions_address_present` check ({$charLength}(trim(path)) > 0 and {$charLength}(trim(sha256)) = 64 and {$charLength}(trim(mime)) > 0)");

        // A clean result without a scan time is a claim, not a record. §17 Q8 stays open, so this
        // is the constraint that keeps the honest reporting possible either way.
        DB::statement('alter table `document_versions` add constraint `document_versions_scan_recorded` check (virus_scan_status = \'pending\' or scanned_at is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
