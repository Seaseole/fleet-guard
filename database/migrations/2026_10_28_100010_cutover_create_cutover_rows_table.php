<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §15.2 Phase 0.5 CUTOVER_ROW — one parsed line of an uploaded file, kept whether it validated
     * or not.
     *
     * Rejected rows are stored rather than discarded because the error report is a deliverable:
     * the accountant fixes the spreadsheet from the same line numbers the screen showed, and the
     * row survives as evidence of what was refused. Amounts on a rejected row are best-effort
     * (unparseable input stores 0 and an error message) — they are never posted, because commit
     * reads only `status = 'valid'`.
     *
     * `gl_account_id` is resolved at validation time and stored so the preview and the commit agree
     * about which account a code meant. The ledger schema intentionally carries no FK on its own
     * dimension columns yet (§20.4); this table names a real chart row, so it can.
     */
    public function up(): void
    {
        Schema::create('cutover_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('cutover_batch_id')->constrained('cutover_batches')->cascadeOnDelete();

            // The physical line in the uploaded file, header being line 1. Blank lines are
            // skipped but still counted, so a message can point at the spreadsheet's own row.
            $table->unsignedInteger('line_number');

            $table->string('account_code', 20)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->string('description', 255)->nullable();

            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->string('status', 8)->default('error');
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'cutover_batch_id', 'line_number'], 'cutover_rows_company_batch_line_unique');
            $table->index(['company_id', 'cutover_batch_id', 'status'], 'cutover_rows_company_batch_status_index');

            $table->foreign('gl_account_id')->references('id')->on('gl_accounts');
        });

        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_status_allowed` check (status in (\'valid\', \'error\'))');
        // A valid row is a postable line: exactly one side carries money, and it knows its account.
        // MySQL's XOR is the directly readable spelling of "exactly one of these".
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_is_one_sided` check (status <> \'valid\' or ((debit_minor > 0) xor (credit_minor > 0)))');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_valid_has_account` check (status <> \'valid\' or gl_account_id is not null)');
        DB::statement('alter table `cutover_rows` add constraint `cutover_rows_error_is_explained` check (status <> \'error\' or `errors` is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cutover_rows');
    }
};
