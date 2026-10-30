<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 WORK_ORDER_LINE — what the work is made of: hours, parts, an outside garage's invoice, a
     * consumable (§10.6).
     *
     * `quantity_x100` is §5.5's scale applied to hours and units, so 2.50 hours is the integer 250 and
     * "1.00 × a tyre" is 100. `unit_cost_minor` is in `currency_code`; `base_amount` is the figure the
     * work order totals, frozen at the line's own write.
     *
     * The arithmetic CHECK restates the *rounding*, not the multiply (§20.25's lesson): the base
     * amount is `quantity × unit cost ÷ 100` rounded HALF_UP once, so the constraint pins a band one
     * minor unit wide around that product rather than claiming an identity a between-cents line can
     * never satisfy. Lines are entered in the company's base currency this phase — the Action refuses
     * anything else and names the supplier bill as the place a foreign invoice will arrive — so the
     * band is honest about what the row can hold.
     *
     * `capital_candidate` marks the lines the close-time decision may capitalise (a rebuilt gearbox is
     * an asset; a service is not), and exists before the decision so the person closing the order is
     * choosing from a list rather than inventing a split.
     */
    public function up(): void
    {
        Schema::create('work_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('work_order_id', 26);

            $table->string('kind', 12);
            $table->string('description', 160);
            $table->unsignedBigInteger('quantity_x100');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('base_amount');

            $table->foreignId('part_id')->nullable()->constrained('parts');
            $table->boolean('capital_candidate')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'work_order_id'], 'work_order_lines_company_order_index');
        });

        DB::statement('alter table `work_order_lines` add constraint `work_order_lines_order_fk` foreign key (`work_order_id`) references `work_orders` (`id`) on delete cascade');

        DB::statement('alter table `work_order_lines` add constraint `work_order_lines_kind_allowed` check (kind in (\'labour\', \'part\', \'external\', \'consumable\'))');
        DB::statement('alter table `work_order_lines` add constraint `work_order_lines_quantity_positive` check (quantity_x100 > 0 and unit_cost_minor > 0 and base_amount > 0)');
        DB::statement('alter table `work_order_lines` add constraint `work_order_lines_currency_upper` check (currency_code = upper(currency_code))');

        // quantity_x100 × unit_cost_minor is already in 1/100 minor units; dividing by 100 and rounding
        // half up gives base_amount. The band is that rounding restated so an unsigned column cannot
        // underflow.
        DB::statement('alter table `work_order_lines` add constraint `work_order_lines_amount_is_rounded_product` check (
            base_amount * 100 >= quantity_x100 * unit_cost_minor - 49
            and base_amount * 100 <= quantity_x100 * unit_cost_minor + 50
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_lines');
    }
};
