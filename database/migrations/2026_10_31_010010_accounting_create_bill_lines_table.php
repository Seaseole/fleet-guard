<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a supplier bill, and the leg of the fleet its cost belongs to.
     *
     * The line is the granularity where the masks survive: `5100 Fuel` requires truck **and**
     * trip, `5300 Maintenance` and `5310 Parts` require a truck, and a garage bill that named none
     * would quietly land the cost in overheads. So a line carries its own `truck_id`/`trip_id`, and
     * the Action refuses a line whose account demands a dimension the line does not name — the same
     * rule the ledger itself enforces at post time, caught here so the screen can say which line.
     *
     * `expense_account_code` is the account this line debits, **copied at write time** from the
     * category mapping — the same decision `invoice_lines.revenue_account_code` records:
     * if the accountant re-codes the chart next year, posted rows keep saying what they actually
     * posted to.
     *
     * `work_order_id` is a names-only reference (a plain char(26), not an FK): The edge runs
     * Accounting → Maintenance, and the line does not read or write the order — the ledger's own
     * `work_order_id` dimension is where the join belongs when the reports arrive.
     *
     * VAT is per line, rounded once, and stored (sum of rounded lines, never round-of-sum);
     * a rateless line carries no tax at all, which the CHECK below makes a rule rather than a
     * convention. A voided bill stamps `released_at` on its lines instead of deleting them (the
     * documents keep their own record even when the money is unposted).
     */
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('bill_id', 26);

            $table->string('description', 160);
            $table->string('category', 16);
            $table->string('expense_account_code', 8);

            $table->foreignId('truck_id')->nullable()->constrained('trucks');
            $table->char('trip_id', 26)->nullable();
            $table->char('work_order_id', 26)->nullable();

            // Net and VAT stored per line; the bill's gross is their sum, never its own input.
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('net_base');
            $table->char('currency_code', 3);

            $table->char('tax_rate_id', 26)->nullable();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tax_base')->default(0);

            $table->unsignedInteger('position')->default(0);
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'bill_id'], 'bill_lines_company_bill_index');
            $table->index(['company_id', 'truck_id'], 'bill_lines_company_truck_index');
            $table->index(['company_id', 'trip_id'], 'bill_lines_company_trip_index');
        });

        DB::statement('alter table `bill_lines` add constraint `bill_lines_bill_fk` foreign key (`bill_id`) references `bills` (`id`) on delete cascade');
        DB::statement('alter table `bill_lines` add constraint `bill_lines_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');
        DB::statement('alter table `bill_lines` add constraint `bill_lines_tax_rate_fk` foreign key (`tax_rate_id`) references `tax_rates` (`id`)');

        DB::statement('alter table `bill_lines` add constraint `bill_lines_category_allowed` check (category in (\'fuel\', \'parts\', \'labour\', \'insurance\', \'lease\', \'other\'))');
        DB::statement('alter table `bill_lines` add constraint `bill_lines_net_positive` check (net_amount > 0 and net_base > 0)');
        DB::statement('alter table `bill_lines` add constraint `bill_lines_tax_needs_a_rate` check (
            (tax_rate_id is null and tax_amount = 0 and tax_base = 0)
            or (tax_rate_id is not null and tax_amount < net_amount and tax_base < net_base)
        )');
        DB::statement('alter table `bill_lines` add constraint `bill_lines_currency_upper` check (currency_code = upper(currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
    }
};
