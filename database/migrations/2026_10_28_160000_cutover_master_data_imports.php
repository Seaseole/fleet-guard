<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 — the same batch table, a second destination.
     *
     * A master file validates into `cutover_rows.payload` because its columns belong to the file, not
     * to the journal: nine datasets share one table, and a typed column per spreadsheet field would
     * be nine tables in disguise. The trial balance keeps its typed columns, which is what posting
     * and reversal read.
     *
     * `applied` is a terminal state next to `posted`/`reversed`: the handler upserts the module's
     * master rows inside one transaction and this row records who did it. There is no reversal,
     * because a rollback of thousands of upserts is a data decision, not a mirror entry.
     *
     * The row CHECKs are restated with the payload escape: a valid master row has no amount and no
     * account, and a valid trial-balance row still must have exactly one side and its account.
     */
    public function up(): void
    {
        Schema::table('cutover_batches', function (Blueprint $table): void {
            $table->timestamp('applied_at')->nullable()->after('reversed_at');
            $table->foreignId('applied_by')->nullable()->after('reversed_by')->constrained('users');
        });

        Schema::table('cutover_rows', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('errors');
        });

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_status_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_status_allowed` check (status in ('validated', 'rejected', 'posted', 'reversed', 'applied'))");

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts'))");

        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_applied_has_actor` check (status <> \'applied\' or (applied_at is not null and applied_by is not null))');

        DB::statement('alter table `cutover_rows` drop check `cutover_rows_valid_is_one_sided`');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_is_one_sided` check (status <> \'valid\' or (payload is not null or ((debit_minor > 0) xor (credit_minor > 0))))');

        DB::statement('alter table `cutover_rows` drop check `cutover_rows_valid_has_account`');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_has_account` check (status <> \'valid\' or (payload is not null or gl_account_id is not null))');
    }

    public function down(): void
    {
        // A master batch is a payload, not a journal: rolling the importer back cannot leave the
        // batch storable, so its batches and rows go. The module records an earlier apply created
        // are ordinary master rows and survive — they are corrected on their own screens.
        DB::table('cutover_batches')->where('dataset', '<>', 'opening_trial_balance')->delete();

        DB::statement('alter table `cutover_rows` drop check `cutover_rows_valid_is_one_sided`');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_is_one_sided` check (status <> \'valid\' or ((debit_minor > 0) xor (credit_minor > 0)))');

        DB::statement('alter table `cutover_rows` drop check `cutover_rows_valid_has_account`');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_has_account` check (status <> \'valid\' or gl_account_id is not null)');

        Schema::table('cutover_rows', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_applied_has_actor`');
        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance'))");

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_status_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_status_allowed` check (status in ('validated', 'rejected', 'posted', 'reversed'))");

        Schema::table('cutover_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn('applied_at');
        });
    }
};
