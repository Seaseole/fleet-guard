<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A trip cost can be settled from stock (§6.8, `FuelIssueRecorded` for a bulk tank): diesel that
     * was already paid for when it entered the tank is not paid for again when a truck takes it, so
     * the cost's credit side is `1180 Bulk fuel stock` and not a cash or payable account.
     *
     * The enum stays the single vocabulary; this migration only teaches the table the new word.
     * The trip page's own dialog does **not** offer it — a toll cannot be paid with diesel — which
     * is why the enum separates `manualOptions()` from its full case list.
     */
    public function up(): void
    {
        DB::statement('alter table `trip_costs` drop check `trip_costs_paid_with_allowed`');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_paid_with_allowed` check (paid_with in (\'petty_cash\', \'bank\', \'supplier_credit\', \'stock\'))');
    }

    public function down(): void
    {
        DB::statement('alter table `trip_costs` drop check `trip_costs_paid_with_allowed`');
        DB::statement('alter table `trip_costs` add constraint `trip_costs_paid_with_allowed` check (paid_with in (\'petty_cash\', \'bank\', \'supplier_credit\'))');
    }
};
