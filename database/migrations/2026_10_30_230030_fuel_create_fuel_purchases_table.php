<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 FUEL_PURCHASE — one slip: what the station charged the fleet, with §5.2's FX block.
     *
     * `amount` is minor units of `currency_code` and `base_amount` is the authoritative base figure,
     * frozen at write time, exactly as `trip_costs` does it. The spec's two "×100 = 2 dp" integer
     * columns are kept as stated — `litres` and `price_per_litre_minor` — because a slip's arithmetic
     * (litres × price) is the first thing an accountant checks, and integer minor units make that
     * check exact rather than a tolerance. "Exact" means the CHECK reproduces §5.5's rounding rule
     * to the minor unit, not that the product is itself a whole minor unit — see the constraint at
     * the foot of `up()`.
     *
     * `posted_to_ledger` is the §6.8 `FuelPurchaseRecorded` seam. Phase 3a records the slip and leaves
     * the flag false: the ledger entry (`Dr 5100 Fuel / Cr 3100 Supplier payable`) needs a supplier
     * bill or a pump-and-pay cash line, and supplier bills are Phase 5's. The column is present so the
     * Phase 5 poster has nowhere new to invent, and the verify script asserts the seam stays shut.
     *
     * `source_uuid` is the idempotency key for the cutover import and any future card-feed: a
     * re-delivered slip is the same purchase, not two.
     */
    public function up(): void
    {
        Schema::create('fuel_purchases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('fuel_station_id')->nullable()->constrained('fuel_stations');
            $table->foreignId('fuel_card_id')->nullable()->constrained('fuel_cards');

            // §5.2, applied verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3);
            $table->unsignedBigInteger('base_amount');

            $table->date('purchased_on');
            $table->unsignedInteger('litres');
            $table->unsignedSmallInteger('price_per_litre_minor');
            $table->string('invoice_no', 60)->nullable();

            $table->boolean('posted_to_ledger')->default(false);
            $table->string('source_uuid', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'purchased_on'], 'fuel_purchases_company_date_index');
            $table->unique(['company_id', 'source_uuid'], 'fuel_purchases_company_source_unique');
        });

        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_litres_positive` check (litres > 0)');
        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');

        // The slip's arithmetic, §5.5's rule stated as integers: `amount = ROUND_HALF_UP(litres ×
        // price ÷ 100)`. Litres are stored ×100 (two decimals at a pump) and the price is currency
        // minor units, so their product is in *hundredths* of a minor unit and only lands on a
        // whole cent when it happens to be a multiple of 100 — 25.30 L at BWP 19.75 is BWP 499.675,
        // which the register must carry as 499.68, not refuse. Writing the constraint as the bare
        // identity `amount × 100 = litres × price` therefore failed ~99 slips in 100.
        //
        // Half-up means `100 × amount − litres × price` is in [−49, +50], and since that window is
        // 100 integers wide it pins exactly one figure: the amount the slip's own two numbers
        // produce. The band is written as two additions rather than a subtraction so neither side
        // can go negative (a `BIGINT UNSIGNED` difference raises instead of comparing), and it is
        // portable to the test engine, where `DIV` is not. A transposed digit still fails on the
        // row that carries it instead of at reconciliation.
        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_price_times_litres_is_amount` check (amount * 100 <= litres * price_per_litre_minor + 50 and litres * price_per_litre_minor <= amount * 100 + 49)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_purchases');
    }
};
