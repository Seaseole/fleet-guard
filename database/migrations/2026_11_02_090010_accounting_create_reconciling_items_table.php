<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reconciling items of a bank reconciliation (Phase 5, slice 5d): the lines between
     * the bank's figure and the book's — deposits in transit, unpresented cheques, bank charges
     * nobody recorded yet, interest, errors — each stored with an explicit direction so the
     * formula is `book + Σadd − Σsub = statement` and the person entering a line decides what it
     * does, not a label that means different things in different accountants' heads.
     *
     * Items live only while the run is open: an approved run is a signed document (the rule
     * that a correction is void + restart), and a voided run keeps its items for the audit trail —
     * `on` the day, in the words of whoever entered them. Amounts are positive minor units of the
     * run's currency; the sign lives in `direction` alone, so the CHECK can be a CHECK.
     */
    public function up(): void
    {
        Schema::create('reconciling_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->char('reconciliation_id', 26);

            $table->string('kind', 24);
            $table->string('direction', 8);

            // Money never exists without a currency code beside it — the line inherits the
            // run's currency at entry, so the row is legible outside the run.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('base_amount');

            $table->date('on_date')->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('note', 500)->nullable();

            $table->unsignedBigInteger('entered_by')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'reconciliation_id'], 'reconciling_items_company_reconciliation_index');
        });

        DB::statement('alter table `reconciling_items` add constraint `reconciling_items_reconciliation_fk` foreign key (`reconciliation_id`) references `reconciliations` (`id`) on delete cascade');
        DB::statement('alter table `reconciling_items` add constraint `reconciling_items_kind_allowed` check (kind in (\'deposit_outstanding\', \'cheque_unpresented\', \'bank_charge\', \'interest_earned\', \'fx_adjustment\', \'book_error\', \'other\'))');
        DB::statement('alter table `reconciling_items` add constraint `reconciling_items_direction_allowed` check (direction in (\'add\', \'sub\'))');
        DB::statement('alter table `reconciling_items` add constraint `reconciling_items_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `reconciling_items` add constraint `reconciling_items_currency_upper` check (currency_code = upper(currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciling_items');
    }
};
