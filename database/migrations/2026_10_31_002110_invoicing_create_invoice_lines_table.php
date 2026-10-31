<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 — one billed line, and the leg of the fleet it earned money on.
     *
     * A line carries `chargeable_id`, `trip_id` and `truck_id` because account `4100 Haulage revenue`
     * requires all of truck, trip and customer (§6.1's mask), so a consolidated invoice cannot post one
     * lump credit to it — the block would name a customer and no leg. The line is the granularity where
     * the dimension survives, which is also what makes revenue-per-truck an ordinary `GROUP BY` (§6.2)
     * rather than a pivot.
     *
     * `revenue_account_code` is the account this line credited, **copied at write time**. §6.8's mapping
     * from a chargeable type to an account is the design's answer today; if the accountant re-codes the
     * chart next year, the rows already posted must keep saying what they actually posted to. The
     * ledger's own line points at the account id; this column is the document's statement of intent.
     *
     * VAT is per line and rounded once (§5.5: "sum of rounded line amounts, never round-of-sum"), so
     * `tax_amount` is stored rather than derived at print time — an invoice whose VAT is recomputed by a
     * report is an invoice that can disagree with the return filed from it.
     *
     * `tax_rate_id` NULL means the line carries no VAT at all (a manual line with no rate chosen); the
     * CHECK forces the tax figures to 0 in that case, so a rate cannot go missing quietly.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('invoice_id', 26);

            $table->string('description', 160);
            $table->string('line_kind', 24);

            $table->char('chargeable_id', 26)->nullable();
            $table->char('trip_id', 26)->nullable();
            $table->char('job_id', 26)->nullable();
            $table->foreignId('truck_id')->nullable();

            $table->string('revenue_account_code', 8);

            // quantity_x100 × unit_price_minor is §5.5's scale again: 27.55 t at P350.00/t is
            // 2755 × 35000 ÷ 100 minor units, with no float in the path.
            $table->unsignedBigInteger('quantity_x100')->default(100);
            $table->unsignedBigInteger('unit_price_minor');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('base_amount');

            $table->char('tax_rate_id', 26)->nullable();
            $table->char('tax_kind', 16)->nullable();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tax_base')->default(0);

            $table->unsignedInteger('position')->default(0);

            // A line is released, never deleted, when its invoice is voided (§10.4: the document keeps its
            // own record even when the money is unposted). `live_chargeable_id` is the stored projection the
            // uniqueness rule below is really about.
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'invoice_id'], 'invoice_lines_company_invoice_index');
            $table->index(['company_id', 'trip_id'], 'invoice_lines_company_trip_index');
            $table->index(['company_id', 'truck_id'], 'invoice_lines_company_truck_index');
        });

        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_invoice_fk` foreign key (`invoice_id`) references `invoices` (`id`) on delete cascade');

        /*
         * A line is billed **once at a time**: one chargeable cannot fund two live lines, which is the
         * invoice-side mirror of `BuildChargeablesFromPod`'s idempotency key (§20.17).
         *
         * "At a time" is the part a plain unique on `chargeable_id` got wrong — it made a voided delivery
         * permanently unbillable, because a re-issue has to reference the same chargeable again (§20.33).
         * The generated column is the uniqueness subject: present while the line is live, NULL once it is
         * released, and MySQL treats NULLs as distinct, so a released line stops competing with its
         * replacement. InnoDB cannot express a partial index, so this is the dialect's own way of saying
         * "unique where not released" — and it stays a database rule rather than a convention.
         */
        DB::statement('alter table `invoice_lines` add column `live_chargeable_id` char(26) generated always as (case when `released_at` is null then `chargeable_id` else null end) stored');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_live_chargeable_unique` unique (`company_id`, `live_chargeable_id`)');

        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_job_company_fk` foreign key (`job_id`, `company_id`) references `freight_jobs` (`id`, `company_id`)');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_truck_fk` foreign key (`truck_id`) references `trucks` (`id`)');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_tax_rate_fk` foreign key (`tax_rate_id`) references `tax_rates` (`id`)');

        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_kind_allowed` check (line_kind in (\'freight\', \'detention\', \'accessories\', \'customs_recharge\', \'fuel_recharge\', \'other\', \'manual\'))');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_tax_kind_allowed` check (tax_kind is null or tax_kind in (\'standard\', \'zero_rated\', \'exempt\'))');

        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_amount_positive` check (amount > 0 and base_amount > 0 and unit_price_minor > 0 and quantity_x100 > 0)');

        // The line's own arithmetic, restated as a band one minor unit wide around the product (§20.28's
        // lesson: pin the rounding, not the multiply).
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_amount_is_rounded_product` check (
            amount * 100 >= quantity_x100 * unit_price_minor - 49
            and amount * 100 <= quantity_x100 * unit_price_minor + 50
        )');

        // A rateless line carries no VAT, and a line never carries more VAT than it is worth.
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_tax_needs_a_rate` check (
            (tax_rate_id is null and tax_kind is null and tax_amount = 0 and tax_base = 0)
            or (tax_rate_id is not null and tax_amount <= amount and tax_base <= base_amount)
        )');
        DB::statement('alter table `invoice_lines` add constraint `invoice_lines_currency_upper` check (currency_code = upper(currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
