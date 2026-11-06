<?php

declare(strict_types=1);

use App\Domain\Operations\Support\JobProgress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * JOB — the commercial promise the whole operational model starts from.
     *
     * The table is `freight_jobs`, not `jobs`: Laravel's database queue driver hardcodes a table
     * called `jobs` (`config/queue.php` connections.database, used since Phase 0), and the first
     * `migrate` proved the collision — `SQLSTATE[42S01]: Table 'jobs' already exists`. The aggregate
     * stays `Job` in code and `JOB-00042` on paper; only the storage name carries the qualifier, so
     * the queue's worker table and the commercial register cannot be confused by a human reading SQL.
     *
     * A job is what the customer asked for (corridor, price, dates); a trip is the vehicle-and-driver
     * execution of it (the first structural rule). Multi-leg work is several trips against the same
     * job, so there is no self-reference here and no `trip_id`.
     *
     * Money keeps the three columns the design gives a job — `quoted_amount`, `currency_code`, `base_amount`
     * — and deliberately **not** the eight-column FX block of, whose verbatim table list names
     * invoices, bills, payments, trip_costs and the ledger but not jobs. A quote is an offer, not a
     * posting: the rate that converts it lives in `exchange_rates` for the date the quote was struck,
     * and the authoritative FX block is carried by the invoice that Phase 4 raises from it.
     *
     * `status` holds only the states a person can set. `all_trips_completed`, `ready_to_invoice`,
     * `invoiced` and `settled` are aggregates over the job's trips and invoices, so they are computed
     * ({@see JobProgress}) and no column can lie about them.
     */
    public function up(): void
    {
        Schema::create('freight_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('job_no', 32);
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('route_id')->constrained('routes');

            $table->string('status', 24)->default('enquiry');
            $table->string('previous_state', 24)->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->string('commodity', 120)->nullable();
            $table->decimal('tonnes_10_2', 8, 2)->nullable();

            $table->string('rate_type', 16)->nullable();
            $table->unsignedBigInteger('quoted_amount')->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->unsignedBigInteger('base_amount')->default(0);
            $table->date('quoted_on')->nullable();

            $table->date('required_load_on')->nullable();
            $table->date('required_delivery_on')->nullable();

            $table->json('terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'job_no'], 'freight_jobs_company_job_no_unique');
            // The fail-closed rule, expressed in the engine: `trips` points at this table with a
            // composite key, so a trip can only ever belong to the same company as its job. A bare
            // FK on `job_id` accepts a cross-tenant pair — the verification caught exactly that — and
            // an application filter is not a substitute for the row that names the money.
            $table->unique(['id', 'company_id'], 'freight_jobs_id_company_unique');
            $table->index(['company_id', 'status'], 'freight_jobs_company_status_index');
            $table->index(['company_id', 'customer_id'], 'freight_jobs_company_customer_index');
            $table->index(['company_id', 'required_load_on'], 'freight_jobs_company_required_load_index');
        });

        // The storable half of the machine. The four derived badges are absent by construction, so
        // a hand-set `invoiced` is a schema error rather than an application convention.
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_status_allowed` check (status in (\'enquiry\', \'quoted\', \'accepted\', \'in_execution\', \'suspended\', \'declined\', \'cancelled\', \'closed\'))');
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_previous_state_allowed` check (previous_state is null or previous_state in (\'enquiry\', \'quoted\', \'accepted\', \'in_execution\', \'suspended\', \'declined\', \'cancelled\', \'closed\'))');
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_rate_type_allowed` check (rate_type is null or rate_type in (\'per_tonne\', \'per_trip\', \'per_km\'))');
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_tonnes_not_negative` check (tonnes_10_2 is null or tonnes_10_2 >= 0)');

        // A number without a currency is a rumour, and a currency with no number is a leftover from an
        // edited screen. The two must agree in both directions.
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_quote_is_a_pair` check ((quoted_amount > 0 and currency_code is not null) or (quoted_amount = 0 and currency_code is null))');
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_base_agrees_with_quote` check ((base_amount > 0 and quoted_amount > 0) or (base_amount = 0 and quoted_amount = 0))');

        // The delivery promise cannot precede the pickup promise.
        DB::statement('alter table `freight_jobs` add constraint `freight_jobs_dates_ordered` check (required_load_on is null or required_delivery_on is null or required_delivery_on >= required_load_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_jobs');
    }
};
