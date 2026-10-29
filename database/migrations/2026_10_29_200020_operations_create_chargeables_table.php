<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 CHARGEABLE — what a trip earns before it becomes an invoice line.
     *
     * Billing is Phase 4, so `status` stops at `ready` here; the table exists now because §16 puts
     * `BuildChargeablesFromPod` on the POD approval and §6.9's tie-out needs the row to exist before
     * the invoice does. `source_uuid` is the idempotency key: approving a proof twice (a retry, a raced
     * click) must not bill the customer twice.
     *
     * `job_id` and `trip_id` are both stored because one job may run as several legs and the invoice
     * gathers by customer, while the profitability view needs the leg.
     */
    public function up(): void
    {
        Schema::create('chargeables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->char('trip_id', 26);
            $table->char('job_id', 26);
            $table->foreignId('customer_id')->constrained('customers');

            $table->string('type', 24);
            $table->string('basis', 16);
            $table->string('description', 160)->nullable();

            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('base_amount');
            $table->string('status', 16)->default('pending');
            $table->string('source_uuid', 80);

            $table->timestamps();

            $table->unique(['company_id', 'source_uuid'], 'chargeables_source_unique');
            $table->index(['company_id', 'status'], 'chargeables_company_status_index');
            $table->index(['company_id', 'trip_id'], 'chargeables_company_trip_index');
            $table->index(['company_id', 'customer_id', 'status'], 'chargeables_company_customer_status_index');
        });

        DB::statement('alter table `chargeables` add constraint `chargeables_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');
        DB::statement('alter table `chargeables` add constraint `chargeables_job_company_fk` foreign key (`job_id`, `company_id`) references `freight_jobs` (`id`, `company_id`)');

        DB::statement('alter table `chargeables` add constraint `chargeables_type_allowed` check (type in (\'freight\', \'detention\', \'accessories\', \'customs_recharge\', \'fuel_recharge\', \'other\'))');
        DB::statement('alter table `chargeables` add constraint `chargeables_basis_allowed` check (basis in (\'per_tonne\', \'per_trip\', \'per_km\', \'fixed\'))');
        DB::statement('alter table `chargeables` add constraint `chargeables_status_allowed` check (status in (\'pending\', \'ready\', \'billed\', \'written_off\'))');
        DB::statement('alter table `chargeables` add constraint `chargeables_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `chargeables` add constraint `chargeables_currency_upper` check (currency_code = upper(currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('chargeables');
    }
};
