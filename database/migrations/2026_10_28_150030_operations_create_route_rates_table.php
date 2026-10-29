<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\CreateRouteRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 ROUTE_RATE — the price card: what one customer pays for one route on one basis, with a
     * window it is valid for.
     *
     * Three structural choices:
     *
     * - **A rate is per customer.** §4.1 makes `customer_id` a required FK, and that is the honest
     *   model for a 21-truck hauler: there is no published tariff, there is "what we agreed with
     *   X". A single "standard" rate would need a nullable customer and then every resolver would
     *   carry a branch for the case nobody actually has.
     * - **The basis is explicit** (`per_tonne | per_trip | per_km`). §4.1's JOB carries its own
     *   `rate_type`, so a job can be priced per trip even where the card says per tonne — but the
     *   card itself must say what one number means, or `150 000` is unreadable a year later.
     * - **The window is data, not history rewriting.** A new price closes the old window (valid_to)
     *   and opens a new one; nothing updates an amount in place, because a trip already billed at
     *   the old price must still be explainable. Overlap is refused by
     *   {@see CreateRouteRate}, since MySQL cannot express
     *   "no two windows for the same key intersect" as a constraint.
     *
     * The unique key includes `valid_from` so the same card cannot be entered twice on the same day;
     * the wider overlap rule is the Action's because it needs a range comparison, not an equality.
     */
    public function up(): void
    {
        Schema::create('route_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->foreignId('route_id')->constrained('routes');
            $table->foreignId('customer_id')->constrained('customers');

            $table->string('rate_type', 12);
            $table->unsignedBigInteger('rate_minor_units');
            $table->char('currency_code', 3);

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->timestamps();

            $table->unique(
                ['company_id', 'route_id', 'customer_id', 'rate_type', 'valid_from'],
                'route_rates_identity_unique',
            );
            $table->index(['company_id', 'customer_id', 'valid_from'], 'route_rates_company_customer_index');
            $table->index(['company_id', 'route_id', 'valid_from'], 'route_rates_company_route_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        DB::statement('alter table `route_rates` add constraint `route_rates_type_allowed` check (rate_type in (\'per_tonne\', \'per_trip\', \'per_km\'))');
        DB::statement('alter table `route_rates` add constraint `route_rates_amount_not_negative` check (rate_minor_units >= 0)');
        // Three-valued logic again: a NULL `valid_to` means "open ended", and without the explicit
        // `is null` arm an UNKNOWN expression would let a window end before it starts.
        DB::statement('alter table `route_rates` add constraint `route_rates_window_ordered` check (valid_to is null or valid_to >= valid_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('route_rates');
    }
};
