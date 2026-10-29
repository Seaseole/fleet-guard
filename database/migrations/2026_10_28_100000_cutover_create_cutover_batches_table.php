<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §15.2 Phase 0.5 CUTOVER_BATCH — one validated upload of legacy data.
     *
     * The rehearsal §15.2 asks for is "validate, preview, then post" in that order, so the batch
     * row is written by validation rather than by the commit: a file that fails leaves a rejected
     * batch and its rows behind, which is what makes the error report reproducible instead of a
     * message somebody had to screenshot.
     *
     * `public_id` is the idempotency key handed to the ledger (`source_id`), and the two event ids
     * are minted once at import rather than at commit. A commit that posts the entry and then dies
     * before writing `journal_entry_id` is retried with the same triple and returns the entry it
     * already made (§6.3); minting at commit would post the opening balances twice.
     *
     * Opening balances are base-currency only: the legacy system's foreign balances arrive as
     * separate converted figures, and an opening TB that mixes currencies cannot be checked
     * against one total. `currency_code` records which currency the upload was read in.
     */
    public function up(): void
    {
        Schema::create('cutover_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->char('public_id', 26);
            $table->string('dataset', 40);
            $table->string('status', 16)->default('validated');

            $table->string('original_filename', 255)->nullable();
            $table->char('checksum', 64);
            $table->char('currency_code', 3);

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('error_row_count')->default(0);
            $table->unsignedBigInteger('total_debit')->default(0);
            $table->unsignedBigInteger('total_credit')->default(0);

            // File-level failures (missing header, unbalanced totals, already-posted duplicate)
            // belong to the batch because no single row can carry them.
            $table->json('batch_errors')->nullable();

            $table->char('posting_event_id', 26);
            $table->char('reversal_event_id', 26);
            $table->char('journal_entry_id', 26)->nullable();
            $table->char('reversal_entry_id', 26)->nullable();

            $table->date('posted_on')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'public_id'], 'cutover_batches_company_public_id_unique');
            $table->unique(['company_id', 'posting_event_id'], 'cutover_batches_company_posting_event_unique');
            $table->unique(['company_id', 'reversal_event_id'], 'cutover_batches_company_reversal_event_unique');
            $table->index(['company_id', 'dataset', 'checksum'], 'cutover_batches_company_dataset_checksum_index');
            $table->index(['company_id', 'status', 'created_at'], 'cutover_batches_company_status_created_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('journal_entry_id')->references('id')->on('gl_journal_entries');
            $table->foreign('reversal_entry_id')->references('id')->on('gl_journal_entries');
        });

        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_status_allowed` check (status in (\'validated\', \'rejected\', \'posted\', \'reversed\'))');
        // Widened in the same migration that adds the next importer, exactly as the alert-type
        // CHECK is: a dataset the engine cannot validate must not be storable (§15.2).
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in (\'opening_trial_balance\'))');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_row_count_matches` check (row_count = valid_row_count + error_row_count)');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_validated_is_clean` check (status <> \'validated\' or error_row_count = 0)');
        // The same rule the journal entry enforces (§6.3 layer 2), restated on the batch so a
        // half-written commit cannot be mistaken for a balanced trial balance.
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_posted_is_balanced` check (status not in (\'posted\', \'reversed\') or (total_debit = total_credit and total_debit > 0))');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_posted_has_entry` check (status not in (\'posted\', \'reversed\') or (journal_entry_id is not null and posted_on is not null and posted_at is not null and posted_by is not null))');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_reversal_matches_status` check (reversal_entry_id is null or (status = \'reversed\' and reversed_at is not null and reversed_by is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cutover_batches');
    }
};
