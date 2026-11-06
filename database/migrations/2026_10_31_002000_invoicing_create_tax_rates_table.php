<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q4 — the VAT rate as a row rather than as prose.
     *
     * Q4's answer was allowed to be "configurable `tax_rates`, default 12%", and the only honest way
     * to hold a rate a country can change is a table with a validity window: an invoice issued in
     * December at the old rate must print and post the old rate in March, so the window is carried
     * per row instead of one row being edited in place. That is the same habit the rate cards
     * already use, and the reason `starts_on`/`ends_on` are here rather than `rate` alone.
     *
     * `rate_x10000` stores a **percentage** with two decimals: 12.00% is 120 000 (12 × 10 000) and
     * 12.5% is 125 000, so no float is ever written. Note the two divisions a consumer owes this
     * column: `/ 10 000` gives the percentage back for display, but the *fraction of an amount* is
     * `rate / (10 000 × 100)` — dividing only by 10 000 turns 12% into 1200%, which the design records as
     * the defect the invoice verifier caught. A rate is not money, but it is a number two people must
     * agree about to the cent, and `decimal(7,4)` would let one of them type 0.12.
     *
     * `kind` is the design's zero-rating question, not a label: Q4 asks how an export leg
     * (Botswana→Namibia/SA/Zambia) is treated, and the answer must be a line type the invoice can
     * carry before anyone files a return. `zero_rated` and `exempt` both force the rate to 0 by CHECK
     * and differ in what the VAT return does with the line — which is why they are two values and not
     * one rate of 0%.
     *
     * Company-keyed, like every other row the ledger posts against: two companies on one install
     * can sit in different VAT regimes.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 24);
            $table->string('name', 80);
            $table->string('kind', 16);
            $table->unsignedInteger('rate_x10000');

            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'code', 'starts_on'], 'tax_rates_company_code_window_unique');
            $table->index(['company_id', 'kind', 'is_active'], 'tax_rates_company_kind_active_index');
        });

        DB::statement('alter table `tax_rates` add constraint `tax_rates_kind_allowed` check (kind in (\'standard\', \'zero_rated\', \'exempt\'))');

        // A standard rate is a real percentage; the two non-standard kinds are not "a small rate",
        // they are "no VAT on this line at all", and a row that says zero_rated at 12% would be a
        // line that charges VAT and reports that it did not.
        DB::statement('alter table `tax_rates` add constraint `tax_rates_rate_in_band` check (
            (kind = \'standard\' and rate_x10000 > 0 and rate_x10000 <= 1000000)
            or (kind <> \'standard\' and rate_x10000 = 0)
        )');

        // A window is half-open: the day it ends is the first day it no longer applies, so two
        // adjacent rows cannot both claim the same date. An open-ended row has no end.
        DB::statement('alter table `tax_rates` add constraint `tax_rates_window_ordered` check (ends_on is null or ends_on > starts_on)');
        DB::statement('alter table `tax_rates` add constraint `tax_rates_currency_upper` check (code = upper(code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
