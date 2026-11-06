<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TRIP_COST — one direct cost against a trip, with the FX block verbatim.
     *
     * `amount` is minor units of `currency_code` and `base_amount` is the authoritative BWP figure,
     * frozen at write time. The row is the operational record and the journal entry is the accounting
     * one; `source_type`/`source_id` tie the two together, and the unique pair is what makes a
     * redelivered capture one cost rather than two.
     *
     * `description` is a deliberate addition to the table sketch: a fuel slip with no words on
     * it is unreadable six weeks later, and the alternative — encoding the note in the journal's
     * description only — would hide it from the screen that records it.
     */
    public function up(): void
    {
        Schema::create('trip_costs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('trip_id', 26);

            $table->string('cost_type', 16);
            $table->string('paid_with', 20)->default('petty_cash');
            $table->string('description', 160)->nullable();

            //, Applied verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3);
            $table->unsignedBigInteger('base_amount');

            $table->string('source_type', 32)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->date('occurred_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'trip_id', 'occurred_on'], 'trip_costs_company_trip_date_index');
            $table->unique(['company_id', 'source_type', 'source_id'], 'trip_costs_source_unique');
        });

        DB::statement('alter table `trip_costs` add constraint `trip_costs_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');

        DB::statement('alter table `trip_costs` add constraint `trip_costs_type_allowed` check (cost_type in (\'fuel\', \'toll\', \'border\', \'lodging\', \'advance\', \'labour\', \'parts\', \'other\'))');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_paid_with_allowed` check (paid_with in (\'petty_cash\', \'bank\', \'supplier_credit\'))');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_costs');
    }
};
