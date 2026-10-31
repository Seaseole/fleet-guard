<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §10.5 — the line a credit is for, and the invoice line it corrects.
     *
     * `invoice_line_id` is set when the credit names a billed line (the usual case: one tonnage line was
     * priced wrong), and nullable when it does not (a detention charge that should never have been raised,
     * which the customer disputes as a whole). Naming the line matters for the same reason an invoice line
     * names its trip: §6.1's revenue accounts require dimensions, so a credit that debits `4100 Haulage
     * revenue` has to be able to say which truck and which leg it is taking back.
     *
     * The dimensions are **copied from the invoice line at write time**, not read from it at report time.
     * A credit raised in March against an invoice corrected in June must still debit the truck and trip the
     * revenue was originally credited to, or the profitability report for that leg silently gains money back.
     *
     * VAT mirrors the invoice line's rate, and the tax figures are stored for §5.5's reason: a return
     * recomputed from live rates is a return that no longer matches the one that was filed.
     */
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('credit_note_id', 26);

            $table->char('invoice_line_id', 26)->nullable();
            $table->string('description', 160);
            $table->string('line_kind', 24);

            $table->char('trip_id', 26)->nullable();
            $table->char('job_id', 26)->nullable();
            $table->foreignId('truck_id')->nullable();
            $table->string('revenue_account_code', 8);

            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('base_amount');

            $table->char('tax_rate_id', 26)->nullable();
            $table->char('tax_kind', 16)->nullable();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tax_base')->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'credit_note_id'], 'credit_note_lines_company_note_index');
            $table->unique(['company_id', 'invoice_line_id'], 'credit_note_lines_company_invoice_line_unique');
        });

        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_note_fk` foreign key (`credit_note_id`) references `credit_notes` (`id`) on delete cascade');

        // A correction of the same line twice would credit it into negative revenue, and the invoice line
        // it names must belong to the invoice the note is drawn against.
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_invoice_line_fk` foreign key (`invoice_line_id`) references `invoice_lines` (`id`)');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_trip_fk` foreign key (`trip_id`) references `trips` (`id`)');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_job_fk` foreign key (`job_id`) references `freight_jobs` (`id`)');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_truck_fk` foreign key (`truck_id`) references `trucks` (`id`)');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_tax_rate_fk` foreign key (`tax_rate_id`) references `tax_rates` (`id`)');

        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_kind_allowed` check (line_kind in (\'freight\', \'detention\', \'accessories\', \'customs_recharge\', \'fuel_recharge\', \'other\', \'manual\'))');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_tax_kind_allowed` check (tax_kind is null or tax_kind in (\'standard\', \'zero_rated\', \'exempt\'))');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_tax_needs_a_rate` check (
            (tax_rate_id is null and tax_kind is null and tax_amount = 0 and tax_base = 0)
            or (tax_rate_id is not null and tax_amount <= amount and tax_base <= base_amount)
        )');
        DB::statement('alter table `credit_note_lines` add constraint `credit_note_lines_currency_upper` check (currency_code = upper(currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
