<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (Phase 5 slice 5b) — the two halves a bill was always going to grow.
     *
     * **Links.** Phase 3's bulk deliveries and part receipts already posted their
     * own `Cr 2110` at the movement; the supplier's paper for the same diesel or the same shelf must
     * **document** that obligation, not expense it a second time. A linked bill therefore carries no
     * lines and posts nothing of its own: `linked_source` + `linked_source_id` name the movement, and
     * the Action refuses a bill that asks for both shapes at once. Like the invoice's
     * `live_chargeable_id`, the uniqueness subject is a stored generated column that NULLs
     * when the bill is voided — one live bill per obligation, and a voided paper frees the delivery to
     * be re-billed.
     *
     * **Balances.** `balance_amount`/`balance_base` are a **projection** of the payment rows, exactly
     * as an invoice's balance is: what the allocations decided is the truth, and the column is
     * carried so AP ageing and the payable total are a query rather than a walk over every payment.
     * `partially_paid`/`paid` stay derived — the stored `status` keeps only the lifecycle; the
     * CHECK is widened to the full written list so the schema states the machine the design draws,
     * and the Action refuses anything the door cannot maintain.
     *
     * The currency rule reads as a file-scope heuristic in `lint-architecture` (D6), and this file
     * adds no currency of its own: the balances are projections of `amount`/`base_amount`, whose
     * currency is the `currency_code`/`base_currency_code` pair the create migration declared. The
     * columns carry no new money in a new currency, which is why no `currency_code` column appears here.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('linked_source', 24)->nullable()->after('supplier_doc_no');
            $table->char('linked_source_id', 26)->nullable()->after('linked_source');
            $table->unsignedBigInteger('balance_amount')->default(0)->after('tax_base');
            $table->unsignedBigInteger('balance_base')->default(0)->after('balance_amount');
        });

        DB::statement('alter table `bills` drop check `bills_status_allowed`');
        DB::statement('alter table `bills` add constraint `bills_status_allowed` check (status in (\'draft\', \'recorded\', \'partially_paid\', \'paid\', \'void\'))');

        DB::statement('alter table `bills` add constraint `bills_linked_source_allowed` check (linked_source is null or linked_source in (\'fuel_delivery\', \'stock_receipt\'))');
        DB::statement('alter table `bills` add constraint `bills_link_is_a_pair` check ((linked_source is null and linked_source_id is null) or (linked_source is not null and linked_source_id is not null))');

        DB::statement('alter table `bills` add column `live_linked_source_id` char(26) generated always as (case when `status` <> \'void\' then `linked_source_id` else null end) stored');
        DB::statement('alter table `bills` add constraint `bills_live_link_unique` unique (`company_id`, `live_linked_source_id`)');

        DB::statement('alter table `bills` add constraint `bills_balance_within_amount` check (balance_amount <= amount and balance_base <= base_amount)');
        DB::statement('alter table `bills` add constraint `bills_draft_balance_is_amount` check (status <> \'draft\' or balance_amount = amount)');

        Schema::table('bills', function (Blueprint $table): void {
            $table->index(['company_id', 'status', 'balance_amount'], 'bills_company_status_balance_index');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_company_status_balance_index');
        });

        DB::statement('alter table `bills` drop unique `bills_live_link_unique`');
        DB::statement('alter table `bills` drop column `live_linked_source_id`');
        DB::statement('alter table `bills` drop check `bills_draft_balance_is_amount`');
        DB::statement('alter table `bills` drop check `bills_balance_within_amount`');
        DB::statement('alter table `bills` drop check `bills_link_is_a_pair`');
        DB::statement('alter table `bills` drop check `bills_linked_source_allowed`');
        DB::statement('alter table `bills` drop check `bills_status_allowed`');
        DB::statement('alter table `bills` add constraint `bills_status_allowed` check (status in (\'draft\', \'recorded\', \'void\'))');

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn(['linked_source', 'linked_source_id', 'balance_amount', 'balance_base']);
        });
    }
};
